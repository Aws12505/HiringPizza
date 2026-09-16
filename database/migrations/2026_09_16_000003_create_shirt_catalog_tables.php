<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The colours a shirt can actually be ordered in. Managed, not free
        // hex, so an entry can never ask for a colour we cannot source.
        Schema::create('shirt_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('hex_code', 7);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'shirt_colors_active_order_index');
        });

        // Logos are added over time. Stored exactly as uploaded — SVG or
        // transparent PNG — because a logo's colour never changes, so there
        // is nothing to gain from converting between the two.
        Schema::create('shirt_logos', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'shirt_logos_active_order_index');
        });

        // The shirt artwork itself. Always SVG: the frontend inlines it and
        // recolours it client-side, which is what makes the preview live.
        // Recolourable paths in the artwork must use fill="currentColor".
        Schema::create('shirt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // NULL gender means the template is unisex.
            $table->string('gender', 16)->nullable();
            $table->string('svg_path');
            // {x, y, width, height} in the SVG's own viewBox coordinates, so
            // the frontend converts to percentages once and it scales freely.
            $table->json('print_area');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'gender'], 'shirt_templates_active_gender_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shirt_templates');
        Schema::dropIfExists('shirt_logos');
        Schema::dropIfExists('shirt_colors');
    }
};
