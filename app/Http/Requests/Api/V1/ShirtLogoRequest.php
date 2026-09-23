<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShirtLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $maxKb = (int) config('shirt_milestones.uploads.max_size', 2048);

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            // SVG and transparent PNG are both fine: a logo's colour never
            // changes, so there is nothing to gain from converting between
            // them. SVG uploads are sanitized before they hit disk.
            //
            // Note `mimes:` is deliberately loose here — Laravel's SVG
            // detection is unreliable, so ShirtAssetStorage's sanitizer pass
            // is the real validation for SVG.
            'file' => [
                $isCreate ? 'required' : 'sometimes',
                'file',
                "max:{$maxKb}",
                'mimetypes:image/svg+xml,image/svg,text/plain,text/xml,application/xml,image/png',
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => 'The logo must be an SVG or a PNG.',
        ];
    }
}
