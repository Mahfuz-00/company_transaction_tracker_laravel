<?php

namespace App\Http\Requests\Api;

/**
 * Validates "save a whole day's meal grid" (POST /api/meals/day).
 *
 * SECURITY FIX: the original inline rule was `entries.*.student_id => exists:students,id`
 * (every institution), and the controller never re-checked the student before
 * writing. A crafted `student_id` from another institution could therefore be
 * inserted into the caller's workspace. Scoping the `exists` rule to the active
 * tenant rejects that id at validation time, closing the hole at its source.
 */
class StoreMealDayRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'entries' => ['required', 'array'],
            'entries.*.student_id' => ['required', $this->tenantExists('students')],
            'entries.*.breakfast' => ['nullable', 'integer', 'min:0', 'max:10'],
            'entries.*.lunch' => ['nullable', 'integer', 'min:0', 'max:10'],
            'entries.*.dinner' => ['nullable', 'integer', 'min:0', 'max:10'],
        ];
    }
}
