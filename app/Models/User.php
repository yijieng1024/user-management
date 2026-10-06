<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $phone_number
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $status
 * @property bool $is_admin
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'phone_number', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The allowed values for the status column.
     *
     * @var list<string>
     */
    public const array STATUSES = ['active', 'inactive', 'suspended'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Determine whether the user's status is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine whether the user may use the app (web and API): an active admin.
     */
    public function hasAdminAccess(): bool
    {
        return $this->adminAccessDeniedReason() === null;
    }

    /**
     * Get why the user may not use the app, or null when they may.
     * Used for the login errors (web and API) and the 403 responses.
     */
    public function adminAccessDeniedReason(): ?string
    {
        if (! $this->is_admin) {
            return __('You do not have admin access.');
        }

        if (! $this->isActive()) {
            return __('Your account is not active.');
        }

        return null;
    }

    /**
     * Filter by status and search name, email and phone number.
     * Shared by the Users page and the API so both list users the same way.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function filter(Builder $query, ?string $status, ?string $search): void
    {
        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';

            $query->where(fn (Builder $query) => $query
                ->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone_number', 'like', $term));
        }
    }

    /**
     * Get the identifier stored in the JWT "sub" claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the custom claims added to the JWT.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
