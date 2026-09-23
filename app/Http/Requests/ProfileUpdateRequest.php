<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the "my profile" form (name, email, avatar).
 *
 * A FormRequest is Laravel's home for validation that belongs to one request:
 * `rules()` lists the constraints, `authorize()` gates the request, and
 * `prepareForValidation()` cleans the data BEFORE the rules run. Laravel resolves
 * this class from the controller's type-hint and runs it automatically, so the
 * controller body stays free of validation code.
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Rules are keyed by the input name and hold a list of string checks that all
     * must pass. `Rule::unique(...)->ignore($id)` asserts the email is taken by no
     * user EXCEPT the one being edited — the classic "don't flag my own address as
     * a duplicate" fix.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                // NOTE: no 'lowercase' rule - it silently rejected any uppercase
                // character, which surfaced as a confusing validation failure.
                // The email is normalised instead.
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Optional profile picture.
            'avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_avatar' => ['boolean'],
        ];
    }

    /**
     * Normalise the email to lowercase before validation, so a user typing
     * "Name@Example.com" is accepted rather than rejected.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
