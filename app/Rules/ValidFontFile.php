<?php

namespace App\Rules;

use App\Models\Font;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Magic-byte font validation. An extension can lie (an SVG named `.woff`), so we
 * sniff the leading bytes and require both that they match a known font
 * signature AND that the claimed extension agrees with the sniffed type. This is
 * the primary guard against uploading active content (HTML/SVG/script) that the
 * render page would then load.
 */
class ValidFontFile implements ValidationRule
{
    /** Leading-byte signatures keyed by canonical type. */
    private const SIGNATURES = [
        'woff' => 'wOFF',
        'woff2' => 'wOF2',
        'otf' => 'OTTO',
        // TrueType: 0x00010000, or 'true'/'ttcf' collection headers.
        'ttf' => "\x00\x01\x00\x00",
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, Font::ALLOWED_EXTENSIONS, true)) {
            $fail('The font must be an OTF, TTF, WOFF or WOFF2 file.');

            return;
        }

        $head = (string) file_get_contents($value->getRealPath(), false, null, 0, 4);

        $sniffed = $this->sniff($head);

        if ($sniffed === null) {
            $fail('The uploaded file is not a valid font (its contents do not match a known font format).');

            return;
        }

        // ttf and otf both sit in the sfnt container; a woff/woff2 extension must
        // match its signature exactly, while ttf/otf may be interchangeable
        // sfnt. Reject a clear mismatch (e.g. svg bytes under a .woff name).
        $sfnt = ['ttf', 'otf'];
        $agrees = $sniffed === $extension
            || (in_array($sniffed, $sfnt, true) && in_array($extension, $sfnt, true));

        if (! $agrees) {
            $fail('The font file contents do not match its '.$extension.' extension.');
        }
    }

    private function sniff(string $head): ?string
    {
        foreach (self::SIGNATURES as $type => $signature) {
            if (str_starts_with($head, $signature)) {
                return $type;
            }
        }

        // Other valid sfnt headers.
        if ($head === 'true' || $head === 'ttcf') {
            return 'ttf';
        }

        return null;
    }
}
