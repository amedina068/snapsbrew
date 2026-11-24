<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Support\SignedUrlKeys;
use Closure;
use Illuminate\Http\Request;

class SignedUrlMiddleware
{
    /** The origin of the snap cache key. Never change it: see cacheUrl(). */
    private const CACHE_ORIGIN = 'https://snapsbrew.com';

    /**
     * The parameters that this middleware sets on the request. The signature
     * leaves them out, so a client must never send them.
     */
    private const RESERVED_PARAMETERS = ['identifier', 'api_log_uuid'];

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // The cache key hashes the full URL. A reserved parameter is not
        // signed, so a new value on each request missed the cache and forced
        // a new render. With the reserved parameters refused, every parameter
        // in the URL is signed, and the key is the signed canonical query plus
        // the signature. A normal URL keeps the key it always had.
        foreach (array_keys($request->all()) as $name) {
            if (in_array(strtolower((string) $name), self::RESERVED_PARAMETERS, true)) {
                abort(400, 'The signed URL has a reserved parameter.');
            }
        }

        $template = $this->getTemplate($request->template_id);

        // A deactivated owner of the template gets no render and no cached
        // image, the same as a template that does not exist.
        if (! $template || $this->ownerIsDeactivated($template)) {
            return response()->file(public_path('/images/default-img.png'));
        }

        // Fold the published version into the cache key (F8) so publishing a new
        // version busts the md5(fullUrl) dedup and stops serving a retracted
        // render. Missing version ⇒ empty suffix (back-compat for unpublished).
        $identifier = md5($this->cacheUrl($request).'|v='.($template->published_version_id ?? ''));

        // A cached snap is served before the signature check. The cache key
        // hashes the full URL with its signature, and only a URL with a valid
        // signature renders a snap. So a hit is a URL that a valid key signed.
        // A revoked key keeps the images that it already rendered, but it
        // cannot render a new image.
        $snap = $this->getSnap($identifier);

        if ($snap) {
            return redirect($snap->imageUrl);
        }

        $apiKey = $this->resolveApiKey($request, $template);

        if (! $apiKey) {
            return response()->file(public_path('/images/default-img.png'));
        }

        // The usage log names the user whose key signed the URL.
        $apiLog = ApiLog::create([
            'request' => json_encode($request->all()),
            'user_id' => $apiKey['user_id'],
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'personal_access_token_id' => $apiKey['token_id'],
            'type' => 'signed_url',
        ]);

        $request->merge([
            'api_log_uuid' => $apiLog->uuid,
        ]);

        $request->merge([
            'identifier' => $identifier,
        ]);

        $request->merge([
            'template' => $template,
        ]);

        return $next($request);
    }

    /**
     * The URL that the snap cache key hashes. It keeps the origin that every
     * existing snap was cached under, so the key does not depend on the host
     * that serves the request. A key with the request host misses the cache on
     * a new host, and each image renders again for one more credit.
     */
    private function cacheUrl(Request $request): string
    {
        return self::CACHE_ORIGIN.substr($request->fullUrl(), strlen($request->root()));
    }

    /**
     * @return array{id: int, token_id: int, user_id: int}|null
     */
    private function resolveApiKey(Request $request, Template $template): ?array
    {
        $signature = $request->input('signature');

        if (! is_string($signature) || $signature === '') {
            return null;
        }

        $fields = $request->except(['signature', 'identifier', 'api_log_uuid']);

        $fields = collect($fields)
            ->sortKeys()
            ->map(function ($value, $key) {
                return "{$key}={$value}";
            })
            ->toArray();

        $fields = implode('&', $fields);

        // A template with no company has no keys. No signup path writes one.
        if ($template->company_id === null) {
            return null;
        }

        return SignedUrlKeys::match($template->company_id, $fields, $signature);
    }

    /**
     * Only a snap with a stored image is a cache hit. A row with no image is
     * the remains of a failed render, and a redirect to its empty image URL
     * sends the visitor to the home page. The request renders again instead.
     */
    private function getSnap($identifier)
    {
        return Snap::where('identifier', $identifier)->withImage()->latest('id')->first();
    }

    private function ownerIsDeactivated(Template $template): bool
    {
        return User::query()
            ->whereKey($template->user_id)
            ->whereNotNull('deactivated_at')
            ->exists();
    }

    private function getTemplate($templateId): ?Template
    {
        return Template::uuid($templateId)->first();
    }
}
