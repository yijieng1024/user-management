<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

class BulkDeleteUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAdminAccess() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The exists rule is applied to the whole array, so all IDs are checked
     * in a single query (soft-deleted users count as missing).
     *
     * @return array<string, array<int, ValidationRule|Stringable|string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000', Rule::exists(User::class, 'id')->withoutTrashed()],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.exists' => __('One or more of the selected users do not exist or have already been deleted.'),
        ];
    }
}
