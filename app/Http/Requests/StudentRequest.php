<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\Student;
use App\Support\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update validation for a member (student) record.
 *
 * THE KEY RULE - tenant-scoped unique identifiers
 * -----------------------------------------------
 * A member's identifying field (`roll` - a Roll ID, Employee ID, Member ID or
 * any custom serial) must be UNIQUE WITHIN ITS INSTITUTION and nowhere else.
 * Two different institutions may legitimately use "EMP-001"; the SAME
 * institution must never have two members sharing it.
 *
 * A plain `Rule::unique('students', 'roll')` is WRONG in two ways:
 *   1. It checks the WHOLE table, so institution B could not use an ID that
 *      institution A already took (a false rejection across tenants).
 *   2. It ignores the active tenant entirely, so a duplicate WITHIN one tenant
 *      could slip through if any scoping mistake occurred elsewhere.
 *
 * The rule below pins the uniqueness check to the ACTIVE institution using
 * `where('institution_id', $tenantId)`, giving exactly the required semantics:
 * strict within a tenant, permissive across tenants.
 */
class StudentRequest extends FormRequest
{
    /**
     * Only staff who can manage the roster may submit. Route middleware already
     * enforces `students.manage`; this is defence in depth.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('students.manage') ?? false;
    }

    /**
     * The institution whose scope the uniqueness check runs against.
     *
     * Prefer the record being edited (so an update can never re-scope itself to
     * a different tenant), then the active tenant.
     */
    protected function institutionId(): ?int
    {
        $student = $this->route('student');

        if ($student instanceof Student) {
            return $student->institution_id !== null ? (int) $student->institution_id : null;
        }

        return app(TenantManager::class)->resolveTenantId()
            ?? Institution::current()?->id;
    }

    public function rules(): array
    {
        /** @var Student|null $student */
        $student = $this->route('student');
        $institutionId = $this->institutionId();

        return [
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                // One login backs at most one member record - platform-wide, since
                // a user account itself belongs to exactly one institution.
                Rule::unique('students', 'user_id')->ignore($student?->id),
            ],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],

            'name' => ['required', 'string', 'max:255'],

            /*
             * STRICT, TENANT-SCOPED UNIQUE IDENTIFIER.
             *
             * `roll` doubles as the member's Roll ID / Employee ID / serial. The
             * where() clause confines uniqueness to the active institution, so a
             * duplicate inside one tenant fails validation with a clear message,
             * while another tenant may reuse the same value freely.
             */
            'roll' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('students', 'roll')
                    ->where(fn ($q) => $q->where('institution_id', $institutionId))
                    ->ignore($student?->id),
            ],

            // The linked group must belong to the SAME tenant (no cross-tenant
            // assignment via a crafted department_id).
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where(fn ($q) => $q->where('institution_id', $institutionId)),
            ],

            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * Field-specific, human error copy - the generic "roll has already been
     * taken" would not say WHERE, which is exactly what the admin needs to know.
     */
    public function messages(): array
    {
        return [
            'roll.unique' => 'That ID is already used by another member in this institution. IDs must be unique within an institution.',
            'department_id.exists' => 'The selected group does not belong to this institution.',
            'user_id.unique' => 'That login is already linked to another member.',
        ];
    }

    /**
     * Trim whitespace so " EMP-001 " and "EMP-001" are treated as the same ID
     * for uniqueness purposes - otherwise a trailing space smuggles in a dup.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('roll')) {
            $this->merge(['roll' => is_string($this->input('roll')) ? trim($this->input('roll')) : $this->input('roll')]);
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
