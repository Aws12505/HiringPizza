<?php

namespace Database\Seeders;

use App\Models\ShirtColor;
use Illuminate\Database\Seeder;

/**
 * Starter colours so the entry form and its live preview have something to
 * work with. Not wired into DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=ShirtColorSeeder
 *
 * Replace these with the colours you can actually source.
 */
class ShirtColorSeeder extends Seeder
{
    public function run(): void
    {
        $colors = [
            ['name' => 'Classic Red', 'hex_code' => '#C8102E', 'sort_order' => 10],
            ['name' => 'Navy', 'hex_code' => '#1B2A4A', 'sort_order' => 20],
            ['name' => 'Black', 'hex_code' => '#111111', 'sort_order' => 30],
            ['name' => 'White', 'hex_code' => '#FFFFFF', 'sort_order' => 40],
            ['name' => 'Heather Grey', 'hex_code' => '#9AA0A6', 'sort_order' => 50],
            ['name' => 'Forest Green', 'hex_code' => '#1E5631', 'sort_order' => 60],
        ];

        foreach ($colors as $color) {
            ShirtColor::query()->updateOrCreate(
                ['name' => $color['name']],
                $color + ['is_active' => true]
            );
        }
    }
}
