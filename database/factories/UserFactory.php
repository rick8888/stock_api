<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'demo',
            'role' => 'viewer',
            'title' => fake()->jobTitle(),
            'avatar_hue' => fake()->numberBetween(0, 359),
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
        ];
    }
}
