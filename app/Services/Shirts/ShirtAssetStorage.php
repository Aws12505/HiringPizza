<?php

namespace App\Services\Shirts;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores shirt templates and logos on the public disk.
 *
 * Every SVG that lands here is sanitized first. The frontend inlines the
 * template SVG so it can recolour it, which makes an uploaded file executable
 * markup in the app's own origin. Logos look safer because they render through
 * an <img> tag, but the public disk is symlinked to public/storage and served
 * directly — anyone can open /storage/shirt-logos/3/x.svg as a document, same
 * origin, fully scripted. So both go through the sanitizer.
 */
class ShirtAssetStorage
{
    public const DISK = 'public';

    /**
     * Sanitize and store an SVG. Returns the stored path.
     *
     * @throws ValidationException when the file is not SVG the sanitizer can parse
     */
    public function storeSvg(UploadedFile $file, string $directory, string $field = 'file'): string
    {
        $raw = file_get_contents($file->getRealPath());

        if ($raw === false || trim($raw) === '') {
            throw ValidationException::withMessages([
                $field => 'The file could not be read.',
            ]);
        }

        $sanitizer = new Sanitizer();
        // Blocks <image href="http://evil/"> — render-time SSRF / tracking pixel.
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize($raw);

        // Do not lean on `mimes:svg`; Laravel's SVG detection is unreliable.
        // The sanitizer returning false IS the validation.
        if ($clean === false || trim($clean) === '') {
            throw ValidationException::withMessages([
                $field => 'The file is not a valid SVG.',
            ]);
        }

        $path = $this->buildPath($directory, 'svg');

        Storage::disk(self::DISK)->put($path, $clean);

        return $path;
    }

    /**
     * Store a logo, sanitizing it when it is an SVG and passing PNG through.
     *
     * @return array{path: string, mime_type: string}
     */
    public function storeLogo(UploadedFile $file, string $field = 'file'): array
    {
        if ($this->looksLikeSvg($file)) {
            return [
                'path' => $this->storeSvg($file, 'shirt-logos', $field),
                'mime_type' => 'image/svg+xml',
            ];
        }

        return [
            'path' => $file->store('shirt-logos', self::DISK),
            'mime_type' => $file->getClientMimeType(),
        ];
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function looksLikeSvg(UploadedFile $file): bool
    {
        return strtolower((string) $file->getClientOriginalExtension()) === 'svg'
            || str_contains(strtolower((string) $file->getClientMimeType()), 'svg');
    }

    private function buildPath(string $directory, string $extension): string
    {
        return trim($directory, '/') . '/' . Str::random(40) . '.' . $extension;
    }
}
