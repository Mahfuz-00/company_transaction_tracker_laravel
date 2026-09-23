<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * Validates "add a member" (POST /api/members).
 *
 * `department_id` and `manager_id` are scoped to the caller's institution, so a
 * member can only be attached to a department/staff account inside the same
 * workspace.
 */
class StoreMemberRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'roll' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', $this->tenantExists('departments')],
            'manager_id' => ['nullable', $this->tenantExists('users')],
            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
