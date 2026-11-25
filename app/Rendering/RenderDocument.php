<?php

namespace App\Rendering;

use Illuminate\Support\Facades\Log;

/**
 * Prepares the HTML of a snap for the browser that renders it. Both renderers
 * call it, and only for the render: the stored snap HTML and the HTML preview
 * keep the Google Fonts link and the public asset URLs.
 *
 * 1. The Google Fonts link becomes `@font-face` rules with `data:` URIs.
 *    A family that the server cannot get keeps a link, and the render gets a
 *    `font_unavailable` warning.
 * 2. When the media disk is `public` and `RENDER_ASSET_URL` is set, an asset
 *    URL on the app origin moves to that address, for example
 *    `http://127.0.0.1:8080` for a Chromium in the same container.
 */
class RenderDocument
{
    private const GOOGLE_LINK = '~<link href="https://fonts\.googleapis\.com/css2\?([^"]*)" rel="stylesheet">~';

    public function __construct(
        private FontLibrary $fonts,
        private RenderWarnings $warnings,
    ) {}

    public function prepare(string $html): string
    {
        return $this->rewriteAssetUrls($this->embedFonts($html));
    }

    private function embedFonts(string $html): string
    {
        if (! config('services.render.embed_fonts', true)) {
            return $html;
        }

        $custom = $this->customFamilies($html);

        return preg_replace_callback(self::GOOGLE_LINK, function ($match) use ($custom) {
            $rules = [];
            $missing = [];

            foreach ($this->families($match[1]) as $family) {
                if (in_array(strtolower($family), $custom, true)) {
                    continue;
                }

                $css = $this->fonts->embeddedCss($family);

                if ($css === null) {
                    $missing[] = $family;
                    $this->warnUnavailable($family);

                    continue;
                }

                $rules[] = $css;
            }

            return ($rules ? '<style>'.implode("\n", $rules).'</style>' : '')
                .($missing ? $this->link($missing) : '');
        }, $html);
    }

    /** @return list<string> the unique family names in a css2 query string */
    private function families(string $query): array
    {
        $families = [];

        foreach (explode('&', html_entity_decode($query)) as $pair) {
            if (str_starts_with($pair, 'family=')) {
                $family = trim(urldecode(substr($pair, 7)));

                if ($family !== '' && ! in_array($family, $families, true)) {
                    $families[] = $family;
                }
            }
        }

        return $families;
    }

    /**
     * The families of the company fonts, which SnapBuilder declares in the
     * document. Google does not have them.
     *
     * @return list<string>
     */
    private function customFamilies(string $html): array
    {
        preg_match_all("~@font-face\\{font-family:'([^']+)'~", $html, $matches);

        return array_map('strtolower', $matches[1]);
    }

    /** @param  list<string>  $families */
    private function link(array $families): string
    {
        $query = implode('&', array_map(fn ($family) => 'family='.str_replace('%20', '+', rawurlencode($family)), $families));

        return '<link href="https://fonts.googleapis.com/css2?'.$query.'" rel="stylesheet">';
    }

    private function warnUnavailable(string $family): void
    {
        Log::warning('The renderer could not embed a font.', ['family' => $family]);

        $this->warnings->add([
            'code' => 'font_unavailable',
            'family' => $family,
            'message' => "The font \"{$family}\" is not available on this server. The image can show a fallback font.",
        ]);
    }

    private function rewriteAssetUrls(string $html): string
    {
        $target = rtrim((string) config('services.render.asset_url'), '/');

        if ($target === '' || config('media-library.disk_name') !== 'public') {
            return $html;
        }

        $origins = collect([config('app.url'), ...config('app.previous_urls', []), config('filesystems.disks.public.url')])
            ->map(fn ($url) => $this->origin((string) $url))
            ->filter(fn ($origin) => $origin !== null && $origin !== $target)
            ->unique()
            ->map(fn ($origin) => preg_quote($origin, '~'));

        if ($origins->isEmpty()) {
            return $html;
        }

        // Only a URL that starts a CSS url() or a src attribute moves. Text that
        // names the app URL stays as the user wrote it.
        $pattern = '~(url\(\s*(?:\'|"|&quot;|&#039;)?|src=["\'])(?:'.$origins->implode('|').')(?=[/\'")]|&quot;|&#039;)~i';

        return preg_replace($pattern, '${1}'.$target, $html);
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
