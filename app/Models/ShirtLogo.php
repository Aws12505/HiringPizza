<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShirtLogo extends Model
{
    protected $table = 'shirt_logos';

    protected $guarded = [];

    protected $appends = ['file_url'];

    protected function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        $secure = str_starts_with((string) config('app.url'), 'https://') ? true : null;

        return asset('storage/' . $this->file_path, $secure);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
