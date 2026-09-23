<?php

namespace App\Http\Requests\Api;

use App\Models\Deposit;
use Illuminate\Validation\Rule;

/**
 * Validates "record a deposit" (POST /api/deposits).
 *
 * The `student_id` is scoped to the caller's institution (see
 * ApiFormRequest::tenantExists), so a member id from another workspace is a
 * clean 422 rather than a cross-tenant write.
 */
class StoreDepositRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'student_id' => ['required', $this->tenantExists('students')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'kind' => ['nullable', Rule::in(array_keys(Deposit::KINDS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
