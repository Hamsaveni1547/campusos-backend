<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Default password for factory users (meets the default password policy).
     */
    public const PASSWORD = 'Campus@Pass123';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '9'.fake()->unique()->numerify('#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'status' => UserStatus::Active,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null, 'status' => UserStatus::PendingVerification]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Invited, 'password_changed_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Inactive, 'deactivated_at' => now()]);
    }

    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes) => ['is_platform_admin' => true]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => ['must_change_password' => true]);
    }
}
