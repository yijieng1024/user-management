<?php

use App\Livewire\Forms\UserForm;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    public UserForm $form;

    /**
     * The status filter: empty string means all statuses.
     */
    #[Url]
    public string $status = '';

    /**
     * Free-text search across name, email and phone number.
     */
    #[Url]
    public string $search = '';

    /**
     * IDs of the users ticked for bulk deletion (checkbox values arrive as strings).
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * The user waiting for single-delete confirmation.
     */
    #[Locked]
    public ?int $deletingUserId = null;

    /**
     * Get the current page of users, filtered by status and search term.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::query()
            ->when(
                in_array($this->status, User::STATUSES, true),
                fn ($query) => $query->where('status', $this->status),
            )
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone_number', 'like', $term));
            })
            ->latest()
            ->paginate(10);
    }

    /**
     * Go back to the first page and clear the selection when the status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    /**
     * Go back to the first page and clear the selection when the search term changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    /**
     * Clear the selection when moving to another page, so "select all" only ever covers the visible page.
     */
    public function updatedPaginators(): void
    {
        $this->selected = [];
    }

    /**
     * Open the modal with an empty form for a new user.
     */
    public function create(): void
    {
        $this->form->reset();
        $this->form->resetErrorBag();

        Flux::modal('user-form')->show();
    }

    /**
     * Open the modal with the given user's details.
     */
    public function edit(int $userId): void
    {
        $this->form->setUser(User::findOrFail($userId));

        Flux::modal('user-form')->show();
    }

    /**
     * Create or update the user, depending on the form state.
     */
    public function save(): void
    {
        $isEditingSelf = $this->form->user?->is(Auth::user()) === true;

        if ($isEditingSelf && ! $this->form->is_admin) {
            throw ValidationException::withMessages([
                'form.is_admin' => __('You cannot remove your own admin access.'),
            ]);
        }

        if ($isEditingSelf && $this->form->status !== 'active') {
            throw ValidationException::withMessages([
                'form.status' => __('You cannot change your own status.'),
            ]);
        }

        if ($this->form->user === null) {
            $user = $this->form->store();
            $message = __('User :name created.', ['name' => $user->name]);
        } else {
            $user = $this->form->update();
            $message = __('User :name updated.', ['name' => $user->name]);
        }

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask for confirmation before deleting a single user.
     */
    public function confirmDelete(int $userId): void
    {
        $this->deletingUserId = $userId;

        Flux::modal('confirm-delete')->show();
    }

    /**
     * Soft delete the user awaiting confirmation.
     */
    public function delete(): void
    {
        $userId = $this->deletingUserId;
        $this->deletingUserId = null;

        Flux::modal('confirm-delete')->close();

        if ($userId === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot delete your own account.'));

            return;
        }

        $user = User::findOrFail($userId);
        $user->delete();

        $this->selected = array_values(array_diff($this->selected, [(string) $user->id]));

        Flux::toast(variant: 'success', text: __('User :name deleted.', ['name' => $user->name]));
    }

    /**
     * Ask for confirmation before deleting the selected users.
     */
    public function confirmBulkDelete(): void
    {
        if ($this->selected === []) {
            return;
        }

        Flux::modal('confirm-bulk-delete')->show();
    }

    /**
     * Soft delete all selected users in a single query, never including the logged-in admin.
     */
    public function deleteSelected(): void
    {
        $ids = array_map('intval', $this->selected);

        $deleted = User::query()
            ->whereIn('id', $ids)
            ->whereKeyNot(Auth::id())
            ->delete();

        $this->selected = [];

        Flux::modal('confirm-bulk-delete')->close();
        Flux::toast(variant: 'success', text: trans_choice('{0} No users deleted.|{1} 1 user deleted.|[2,*] :count users deleted.', $deleted));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('Manage user accounts, status and admin access') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-user-button">
            {{ __('Add user') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex w-full items-center gap-2 sm:w-auto sm:gap-4">
            <div class="min-w-0 flex-1 sm:w-72 sm:flex-none">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Search name, email or phone')"
                    :aria-label="__('Search users')"
                    clearable
                    data-test="search-input"
                />
            </div>

            <div class="w-36 shrink-0 sm:w-48">
                <flux:select wire:model.live="status" :aria-label="__('Filter by status')" data-test="status-filter">
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                    @foreach (User::STATUSES as $statusOption)
                        <flux:select.option :value="$statusOption">{{ __(ucfirst($statusOption)) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if (count($selected) > 0)
            <flux:button variant="danger" icon="trash" wire:click="confirmBulkDelete" data-test="bulk-delete-button">
                {{ __('Delete selected (:count)', ['count' => count($selected)]) }}
            </flux:button>
        @endif
    </div>

    <flux:checkbox.group wire:model.live="selected">
        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column class="w-0">
                    <flux:checkbox.all :aria-label="__('Select all users on this page')" />
                </flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Phone number') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Admin') }}</flux:table.column>
                <flux:table.column>{{ __('Created at') }}</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->users as $user)
                    @php($isCurrentUser = $user->id === auth()->id())

                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            @unless ($isCurrentUser)
                                <flux:checkbox :value="(string) $user->id" :aria-label="__('Select :name', ['name' => $user->name])" />
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell variant="strong">
                            {{ $user->name }}
                            @if ($isCurrentUser)
                                <flux:text class="inline text-xs">({{ __('you') }})</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>{{ $user->phone_number }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" :color="match ($user->status) {
                                'active' => 'green',
                                'suspended' => 'red',
                                default => 'zinc',
                            }">{{ __(ucfirst($user->status)) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($user->is_admin)
                                <flux:badge size="sm" inset="top bottom" color="indigo">{{ __('Admin') }}</flux:badge>
                            @else
                                <flux:text>{{ __('No') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->created_at?->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" inset="top bottom" :aria-label="__('Actions for :name', ['name' => $user->name])" data-test="user-actions-button" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $user->id }})">
                                        {{ __('Edit') }}
                                    </flux:menu.item>

                                    @unless ($isCurrentUser)
                                        <flux:menu.separator />

                                        <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $user->id }})">
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    @endunless
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center">{{ __('No users found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:checkbox.group>

    {{-- Create / edit modal --}}
    <flux:modal name="user-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $form->user ? __('Edit user') : __('Add user') }}</flux:heading>

            <flux:input wire:model="form.name" :label="__('Name')" type="text" required autocomplete="off" />
            <flux:input wire:model="form.email" :label="__('Email')" type="email" required autocomplete="off" />
            <flux:input wire:model="form.phone_number" :label="__('Phone number')" type="tel" required maxlength="20" autocomplete="off" />

            <flux:input
                wire:model="form.password"
                :label="__('Password')"
                :description="$form->user ? __('Leave blank to keep the current password.') : null"
                type="password"
                :required="$form->user === null"
                autocomplete="new-password"
                viewable
            />
            <flux:input
                wire:model="form.password_confirmation"
                :label="__('Confirm password')"
                type="password"
                :required="$form->user === null"
                autocomplete="new-password"
                viewable
            />

            <flux:select
                wire:model="form.status"
                :label="__('Status')"
                :description="$form->user?->is(auth()->user()) ? __('You cannot change your own status.') : null"
                :disabled="$form->user?->is(auth()->user()) === true"
                required
            >
                @foreach (User::STATUSES as $statusOption)
                    <flux:select.option :value="$statusOption">{{ __(ucfirst($statusOption)) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox
                wire:model="form.is_admin"
                :label="__('Is admin')"
                :description="$form->user?->is(auth()->user()) ? __('You cannot remove your own admin access.') : null"
                :disabled="$form->user?->is(auth()->user()) === true"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-user-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Single delete confirmation --}}
    <flux:modal name="confirm-delete" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete user?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The user will be removed from the list and can no longer log in.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk delete confirmation --}}
    <flux:modal name="confirm-bulk-delete" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :count selected users?', ['count' => count($selected)]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('The selected users will be removed from the list and can no longer log in.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteSelected" data-test="confirm-bulk-delete-button">{{ __('Delete selected') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
