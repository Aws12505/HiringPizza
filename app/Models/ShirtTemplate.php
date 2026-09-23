<?php

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Model;

class ShirtTemplate extends Model
{
    protected $table = 'shirt_templates';

    protected $guarded = [];

    protected $appends = ['svg_url'];

    protected function getSvgUrlAttribute(): ?string
    {
        if (!$this->svg_path) {
            return null;
        }

        $secure = str_starts_with((string) config('app.url'), 'https://') ? true : null;

        return asset('storage/' . $this->svg_path, $secure);
    }

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'print_area' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
