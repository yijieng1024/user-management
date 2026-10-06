<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use Stringable;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, Password|Stringable|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', $this->uniqueRule('email')],
            'phone_number' => ['required', 'string', 'max:20', $this->uniqueRule('phone_number')],
            'password' => $this->passwordRules(),
            'status' => ['required', 'string', Rule::in(User::STATUSES)],
            'is_admin' => ['boolean'],
        ];
    }

    /**
     * Get the unique rule for the given users column.
     */
    protected function uniqueRule(string $column): Unique
    {
        return Rule::unique(User::class, $column);
    }

    /**
     * Get the validation rules for the password field.
     *
     * @return array<int, Password|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', Password::defaults()];
    }
}
