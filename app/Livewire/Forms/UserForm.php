<?php

namespace App\Livewire\Forms;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
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
        return $this->formRequest()->rules();
    }

    /**
     * Get the custom validation messages from the matching Form Request.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->formRequest()->messages();
    }

    /**
     * Validate the form and create a new user.
     */
    public function store(): User
    {
        $user = app(CreateUser::class)($this->validate());

        $this->reset();

        return $user;
    }

    /**
     * Validate the form and update the user being edited.
     */
    public function update(): User
    {
        /** @var User $user */
        $user = $this->user;

        $user = app(UpdateUser::class)($user, $this->validate());

        $this->reset();

        return $user;
    }

    /**
     * Get the Form Request that holds the rules for the current mode (create or edit).
     */
    private function formRequest(): StoreUserRequest
    {
        return $this->user === null
            ? new StoreUserRequest
            : (new UpdateUserRequest)->forUser($this->user);
    }
}
