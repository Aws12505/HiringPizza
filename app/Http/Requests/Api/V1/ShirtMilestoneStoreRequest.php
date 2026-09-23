<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Employee;

/**
 * Manual creation: create the milestone and fill the form in one call, for
 * when a store wants to give someone a shirt without waiting for a month to
 * come around. Same entry rules, plus the employee to create it for.
 */
class ShirtMilestoneStoreRequest extends ShirtMilestoneEntryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'due_date' => ['nullable', 'date'],
        ]);
    }

    /**
     * Here the employee comes from the body, not from an existing milestone.
     */
    protected function resolveEmployee(): ?Employee
    {
        $employeeId = $this->input('employee_id');

        if (!is_numeric($employeeId)) {
            return null;
        }

        return Employee::query()->with('obsession')->find($employeeId);
    }
}
