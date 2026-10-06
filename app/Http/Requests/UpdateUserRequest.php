<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use LogicException;
use Stringable;

/**
 * Same rules as StoreUserRequest, except that the email and phone number
 * unique checks ignore the user being updated, the password is optional,
 * and admins cannot remove their own admin access or change their own status.
 */
class UpdateUserRequest extends StoreUserRequest
{
    /**
     * The user being updated when the rules are used outside an HTTP route.
     */
    protected ?User $userBeingUpdated = null;

    /**
     * Set the user being updated, e.g. when reusing these rules in a Livewire component.
     */
    public function forUser(User $user): static
    {
        $this->userBeingUpdated = $user;

        return $this;
    }

    /**
     * Get the validation rules. An admin editing their own account must stay
     * an active admin, so they cannot lock themselves out.
     *
     * @return array<string, array<int, Password|Stringable|string>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->isUpdatingOwnAccount()) {
            $rules['is_admin'] = ['sometimes', 'boolean', 'accepted'];
            $rules['status'] = ['required', 'string', Rule::in(['active'])];
        }

        return $rules;
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        if (! $this->isUpdatingOwnAccount()) {
            return [];
        }

        return [
            'is_admin.accepted' => __('You cannot remove your own admin access.'),
            'status.in' => __('You cannot change your own status.'),
        ];
    }

    /**
     * Get the unique rule for the given users column, ignoring the user being updated.
     */
    protected function uniqueRule(string $column): Unique
    {
        return parent::uniqueRule($column)->ignore($this->userBeingUpdated());
    }

    /**
     * Get the validation rules for the password field. A blank password keeps the current one.
     *
     * @return array<int, Password|string>
     */
    protected function passwordRules(): array
    {
        return ['nullable', 'string', 'confirmed', Password::defaults()];
    }

    /**
     * Resolve the user being updated from forUser() or the {user} route parameter.
     */
    protected function userBeingUpdated(): User
    {
        $user = $this->userBeingUpdated ?? $this->route('user');

        if (! $user instanceof User) {
            throw new LogicException('UpdateUserRequest needs the user being updated.');
        }

        return $user;
    }

    /**
     * Determine whether the logged-in admin (web or API guard) is updating their own account.
     */
    protected function isUpdatingOwnAccount(): bool
    {
        return $this->userBeingUpdated()->is(Auth::user());
    }
}
