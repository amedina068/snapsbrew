<?php

namespace App\Services;

use App\Contracts\EditorAssetTransport;
use App\Contracts\EditorAssetUrlResolver;
use App\Models\EditorAsset;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Converts accepted editor image inputs into tenant-owned immutable bytes.
 *
 * Remote fetching uses a dedicated pinned-DNS transport rather than the
 * application's permissive SafeUrl rule. Callers can safely persist only the
 * returned UUID; the original input is never stored.
 */
class EditorAssetIngestion
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private EditorAssetTransport $transport,
        private EditorAssetUrlResolver $resolver,
    ) {}

    public function ingestUrl(int $companyId, string $url): EditorAsset
    {
        return $this->ingestBytes($companyId, $this->download($url));
    }

    public function ingestDataUri(int $companyId, string $dataUri): EditorAsset
    {
        if (! preg_match('/\Adata:image\/(?:jpeg|png|webp);base64,([A-Za-z0-9+\/]*={0,2})\z/D', $dataUri, $matches)) {
            throw new RuntimeException('asset_type_rejected');
        }

        $encoded = $matches[1];
        if (strlen($encoded) > (int) ceil(EditorAsset::MAX_BYTES * 4 / 3)) {
            throw new RuntimeException('asset_limit_exceeded');
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new RuntimeException('asset_type_rejected');
        }

        return $this->ingestBytes($companyId, $bytes);
    }

    public function ingestBytes(int $companyId, string $bytes): EditorAsset
    {
        if ($bytes === '' || strlen($bytes) > EditorAsset::MAX_BYTES) {
            throw new RuntimeException('asset_limit_exceeded');
        }

        $image = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_array($image)
            || ! in_array($mime, EditorAsset::MIME_TYPES, true)
            || ($image['mime'] ?? null) !== $mime
            || ! $this->hasExpectedSignature($bytes, $mime)) {
            throw new RuntimeException('asset_type_rejected');
        }

        $width = (int) $image[0];
        $height = (int) $image[1];
        if ($width < 1 || $height < 1 || $width > EditorAsset::MAX_DIMENSION
            || $height > EditorAsset::MAX_DIMENSION || $width * $height > EditorAsset::MAX_PIXELS) {
            throw new RuntimeException('asset_limit_exceeded');
        }

        $canonical = $this->canonicalPng($bytes);
        if (strlen($canonical) > EditorAsset::MAX_BYTES) {
            throw new RuntimeException('asset_limit_exceeded');
        }
        $digest = hash('sha256', $canonical);

        try {
            return DB::transaction(function () use ($companyId, $digest, $canonical, $width, $height): EditorAsset {
                $existing = EditorAsset::query()
                    ->where('company_id', $companyId)
                    ->where('sha256', $digest)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->hasMedia('source')) {
                        return $existing;
                    }

                    throw new \LogicException('asset_ingestion_incomplete');
                }

                $asset = EditorAsset::create([
                    'company_id' => $companyId,
                    'sha256' => $digest,
                    'mime_type' => 'image/png',
                    'byte_size' => strlen($canonical),
                    'width' => $width,
                    'height' => $height,
                ]);

                $asset->addMediaFromString($canonical)
                    ->usingFileName(Str::uuid().'.png')
                    ->toMediaCollection('source');

                return $asset;
            });
        } catch (QueryException $exception) {
            $existing = EditorAsset::query()
                ->where('company_id', $companyId)
                ->where('sha256', $digest)
                ->first();
            if ($existing && $existing->hasMedia('source')) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function hasExpectedSignature(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'image/webp' => substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function download(string $url): string
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $response = $this->transport->get($url, $this->resolver->resolve($url));

            if ($response->status >= 300 && $response->status < 400 && isset($response->headers['location'])) {
                if ($redirects === self::MAX_REDIRECTS) {
                    throw new RuntimeException('asset_redirect_rejected');
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->headers['location']));

                continue;
            }

            if ($response->overflow) {
                throw new RuntimeException('asset_limit_exceeded');
            }
            if ($response->status !== 200 || $response->timedOut) {
                throw new RuntimeException('asset_url_rejected');
            }

            return $response->body;
        }

        throw new RuntimeException('asset_redirect_rejected');
    }

    private function canonicalPng(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            throw new RuntimeException('asset_type_rejected');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('asset_type_rejected');
        }

        ob_start();
        imagepng($image, null, 6);
        $canonical = (string) ob_get_clean();
        imagedestroy($image);

        return $canonical;
    }
}
