<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One rule set and one store path for every image that a client uploads to
 * the public media disk.
 *
 * The stored name is `<uuid>.<extension of the detected MIME type>`. The
 * client file name never reaches the disk, so a file named `x.html` or
 * `x.svg` cannot become a page on the app origin.
 *
 * Only an `UploadedFile` reaches `addMedia()`. Spatie reads a string as a
 * local path, and it moves that file to the media disk.
 */
final class ImageUpload
{
    /**
     * Raster types only. SVG is not on the list, because the app does not
     * sanitize an uploaded SVG and a browser runs its scripts.
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public const MAX_KILOBYTES = 10240;

    /**
     * `mimes` reads the content and `extensions` reads the client name. The
     * two lists are the same, so a file must pass both checks.
     *
     * @return array<int, string>
     */
    public static function rules(int $maxKilobytes = self::MAX_KILOBYTES): array
    {
        $extensions = implode(',', self::EXTENSIONS);

        return ['file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.$maxKilobytes];
    }

    public static function addTo(HasMedia $model, mixed $file, string $collection): Media
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new InvalidArgumentException('An image upload must be a valid uploaded file.');
        }

        $extension = strtolower((string) $file->guessExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('The uploaded file is not a supported image type.');
        }

        return $model->addMedia($file)
            ->usingFileName(Str::uuid().'.'.$extension)
            ->toMediaCollection($collection);
    }
}
