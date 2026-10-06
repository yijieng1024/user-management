<?php

namespace App\Livewire\Forms;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;
use Stringable;

class UserForm extends Form
{
    /**
     * The user being edited, or null when creating a new user.
     */
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $phone_number = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $status = 'active';

    public bool $is_admin = false;

    /**
     * Fill the form with an existing user for editing.
     */
    public function setUser(User $user): void
    {
        $this->resetErrorBag();

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone_number = $user->phone_number;
        $this->password = '';
        $this->password_confirmation = '';
        $this->status = $user->status;
        $this->is_admin = $user->is_admin;
    }

    /**
     * Get the validation rules from the matching Form Request, so they are defined in one place.
     *
     * @return array<string, array<int, Password|Stringable|string>>
     */
    protected function rules(): array
    {
        return $this->user === null
            ? (new StoreUserRequest)->rules()
            : (new UpdateUserRequest)->forUser($this->user)->rules();
    }

    /**
     * Validate the form and create a new user.
     */
    public function store(): User
    {
        $validated = $this->validate();

        $user = new User(Arr::except($validated, ['is_admin']));
        $user->is_admin = (bool) $validated['is_admin'];
        $user->save();

        $this->reset();

        return $user;
    }

    /**
     * Validate the form and update the user being edited.
     */
    public function update(): User
    {
        $validated = $this->validate();

        /** @var User $user */
        $user = $this->user;

        $user->fill(Arr::except($validated, ['is_admin', 'password']));
        $user->is_admin = (bool) $validated['is_admin'];

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        $user->save();

        $this->reset();

        return $user;
    }
}
