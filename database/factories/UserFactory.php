<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->numerify('01#-#######'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'is_admin' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Fixed, realistic users for the API documentation examples.
     * Scribe builds them with make() only, so they are never saved.
     */
    public function apiDocsExample(): static
    {
        return $this->sequence(
            [
                'id' => 1,
                'name' => 'Ahmad Faizal bin Hassan',
                'email' => 'ahmad.faizal@example.com',
                'phone_number' => '012-3456789',
                'status' => 'active',
                'is_admin' => false,
                'created_at' => '2026-10-01 09:00:00',
                'updated_at' => '2026-10-01 09:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Tan Mei Ling',
                'email' => 'meiling.tan@example.com',
                'phone_number' => '016-7788990',
                'status' => 'suspended',
                'is_admin' => false,
                'created_at' => '2026-10-02 14:30:00',
                'updated_at' => '2026-10-05 10:15:00',
            ],
        );
    }
}
