<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShirtColor extends Model
{
    protected $table = 'shirt_colors';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
