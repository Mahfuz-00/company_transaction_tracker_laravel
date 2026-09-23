<?php

namespace App\Http\Requests\Api;

use App\Models\Subsidy;
use Illuminate\Validation\Rule;

/**
 * Validates "record a subsidy" (POST /api/subsidies).
 *
 * The optional `department_id` / `student_id` targets are scoped to the
 * caller's institution, so a subsidy can never be pointed at another
 * workspace's department or member.
 */
class StoreSubsidyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:60'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_mode' => ['required', Rule::in(array_keys(Subsidy::APPLY_MODES))],
            'department_id' => ['nullable', $this->tenantExists('departments')],
            'student_id' => ['nullable', $this->tenantExists('students')],
            'period_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
