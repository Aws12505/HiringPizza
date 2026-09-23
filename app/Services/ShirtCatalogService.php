<?php

namespace App\Services;

use App\Models\ShirtColor;
use App\Models\ShirtLogo;
use App\Models\ShirtTemplate;
use App\Services\Shirts\ShirtAssetStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

/**
 * The shirt colours, logos and templates the preview is built from.
 *
 * Deletes deactivate rather than remove: milestones reference these rows
 * historically, and what was ordered last year must still read back.
 */
class ShirtCatalogService
{
    public function __construct(
        private readonly ShirtAssetStorage $storage
    ) {
    }

    /**
     * Everything the preview UI needs in one call.
     *
     * Active rows only by default; the management screens ask for the
     * deactivated ones too.
     */
    public function catalog(bool $includeInactive = false): array
    {
        $activeOnly = !$includeInactive;

        return [
            'colors' => $this->colors($activeOnly),
            'logos' => $this->logos($activeOnly),
            'templates' => $this->templates($activeOnly),
        ];
    }

    // -------------------------------------------------------------------------
    // Colors
    // -------------------------------------------------------------------------

    public function colors(bool $activeOnly = false): Collection
    {
        return ShirtColor::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createColor(array $data): ShirtColor
    {
        return ShirtColor::query()->create([
            'name' => $data['name'],
            'hex_code' => $this->normalizeHex($data['hex_code']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateColor(ShirtColor $color, array $data): ShirtColor
    {
        if (array_key_exists('hex_code', $data) && $data['hex_code'] !== null) {
            $data['hex_code'] = $this->normalizeHex($data['hex_code']);
        }

        $color->update($this->onlyPresent($data, ['name', 'hex_code', 'is_active', 'sort_order']));

        return $color->refresh();
    }

    public function deactivateColor(ShirtColor $color): void
    {
        $color->update(['is_active' => false]);
    }

    // -------------------------------------------------------------------------
    // Logos
    // -------------------------------------------------------------------------

    public function logos(bool $activeOnly = false): Collection
    {
        return ShirtLogo::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createLogo(array $data, UploadedFile $file): ShirtLogo
    {
        $stored = $this->storage->storeLogo($file);

        return ShirtLogo::query()->create([
            'name' => $data['name'],
            'file_path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateLogo(ShirtLogo $logo, array $data, ?UploadedFile $file = null): ShirtLogo
    {
        $attributes = $this->onlyPresent($data, ['name', 'is_active', 'sort_order']);

        if ($file !== null) {
            $previous = $logo->file_path;
            $stored = $this->storage->storeLogo($file);

            $attributes['file_path'] = $stored['path'];
            $attributes['mime_type'] = $stored['mime_type'];

            // Only after the replacement is safely on disk.
            $this->storage->delete($previous);
        }

        $logo->update($attributes);

        return $logo->refresh();
    }

    public function deactivateLogo(ShirtLogo $logo): void
    {
        $logo->update(['is_active' => false]);
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    public function templates(bool $activeOnly = false): Collection
    {
        return ShirtTemplate::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByRaw('gender IS NULL')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    public function createTemplate(array $data, UploadedFile $file): ShirtTemplate
    {
        $path = $this->storage->storeSvg($file, 'shirt-templates', 'svg');

        return ShirtTemplate::query()->create([
            'name' => $data['name'],
            'gender' => $data['gender'] ?? null,
            'svg_path' => $path,
            'print_area' => $data['print_area'],
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
        ]);
    }

    public function updateTemplate(ShirtTemplate $template, array $data, ?UploadedFile $file = null): ShirtTemplate
    {
        $attributes = $this->onlyPresent($data, ['name', 'gender', 'print_area', 'is_active', 'is_default']);

        if ($file !== null) {
            $previous = $template->svg_path;
            $attributes['svg_path'] = $this->storage->storeSvg($file, 'shirt-templates', 'svg');

            $this->storage->delete($previous);
        }

        $template->update($attributes);

        return $template->refresh();
    }

    public function deactivateTemplate(ShirtTemplate $template): void
    {
        $template->update(['is_active' => false]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Only write keys the caller actually sent, so a partial update does not
     * blank out everything it left out.
     */
    private function onlyPresent(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    private function normalizeHex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        // Accept the 3-digit shorthand and expand it, so #C13 and #CC1133
        // don't end up as two different catalogue entries.
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtoupper($hex);
    }
}
