<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use LogicException;

/**
 * Same rules as StoreUserRequest, except that the email and phone number
 * unique checks ignore the user being updated and the password is optional.
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
}
