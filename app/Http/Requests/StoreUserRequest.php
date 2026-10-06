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
     * Describe the body parameters for the API documentation (Scribe).
     * The rules above remain the source of types and required fields.
     *
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Full name. Max 255 characters.',
                'example' => 'Ahmad Faizal bin Hassan',
            ],
            'email' => [
                'description' => 'Email address. Must be unique, including soft-deleted users.',
                'example' => 'ahmad.faizal@example.com',
            ],
            'phone_number' => [
                'description' => 'Phone number, stored as text so leading zeros and `+60` are kept. Must be unique, including soft-deleted users. Max 20 characters.',
                'example' => '012-3456789',
            ],
            'password' => [
                'description' => 'Password. At least 8 characters (in production: at least 12, with upper and lower case letters, a number and a symbol, and not found in known data leaks).',
                'example' => 'S3cure!Passw0rd',
            ],
            'status' => [
                'description' => 'One of `active`, `inactive` or `suspended`.',
                'example' => 'active',
            ],
            'is_admin' => [
                'description' => 'Whether the user is an admin.',
                'example' => false,
            ],
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
