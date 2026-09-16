<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ShirtMilestoneSource;
use App\Enums\ShirtMilestoneStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShirtMilestoneIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statuses = array_column(ShirtMilestoneStatus::cases(), 'value');

        return [
            'q' => ['sometimes', 'string', 'max:120'],

            'status' => ['sometimes', Rule::in($statuses)],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['required', Rule::in($statuses)],

            'source' => ['sometimes', Rule::in(array_column(ShirtMilestoneSource::cases(), 'value'))],

            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'milestone_month' => ['sometimes', 'integer', 'min:1'],

            'due_from' => ['sometimes', 'date'],
            'due_to' => ['sometimes', 'date'],

            // Global queue only: store NUMBERS, matching every other endpoint.
            'stores' => ['sometimes', 'array'],
            'stores.*' => ['required', 'string', 'max:64'],

            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
