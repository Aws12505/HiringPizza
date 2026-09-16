<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShirtTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * print_area arrives as a JSON string over multipart, so decode it before
     * the array rules below try to read into it.
     */
    protected function prepareForValidation(): void
    {
        $printArea = $this->input('print_area');

        if (is_string($printArea)) {
            $decoded = json_decode($printArea, true);

            if (is_array($decoded)) {
                $this->merge(['print_area' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $maxKb = (int) config('shirt_milestones.uploads.max_size', 2048);

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],

            // Null means unisex.
            'gender' => ['nullable', Rule::in(array_column(Gender::cases(), 'value'))],

            // Always SVG: the frontend inlines it and recolours it client-side,
            // which is what makes the preview live. Recolourable paths in the
            // artwork must use fill="currentColor".
            //
            // `mimetypes` is a coarse first pass only — ShirtAssetStorage's
            // sanitizer is what actually validates (and cleans) the SVG.
            'svg' => [
                $isCreate ? 'required' : 'sometimes',
                'file',
                "max:{$maxKb}",
                'mimetypes:image/svg+xml,image/svg,text/plain,text/xml,application/xml',
            ],

            // Where the logo sits on the shirt, in the SVG's own viewBox
            // coordinates — so the frontend converts to percentages once and
            // the preview scales for free.
            'print_area' => [$isCreate ? 'required' : 'sometimes', 'array'],
            'print_area.x' => ['required_with:print_area', 'numeric'],
            'print_area.y' => ['required_with:print_area', 'numeric'],
            'print_area.width' => ['required_with:print_area', 'numeric', 'gt:0'],
            'print_area.height' => ['required_with:print_area', 'numeric', 'gt:0'],

            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'svg.mimetypes' => 'The shirt template must be an SVG.',
            'print_area.array' => 'print_area must be an object with x, y, width and height.',
        ];
    }
}
