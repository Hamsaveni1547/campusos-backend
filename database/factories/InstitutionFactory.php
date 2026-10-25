<?php

namespace Database\Factories;

use App\Modules\Institution\Enums\InstitutionStatus;
use App\Modules\Institution\Enums\InstitutionType;
use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    protected $model = Institution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' College of Engineering';

        return [
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            'name' => $name,
            'short_name' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?? 'INST', 0, 6)),
            'type' => InstitutionType::College,
            'status' => InstitutionStatus::Active,
            'email' => fake()->companyEmail(),
            'phone' => '080'.fake()->numerify('########'),
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'IN',
            'postal_code' => '5600'.fake()->numerify('##'),
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'established_year' => fake()->numberBetween(1950, 2015),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => InstitutionStatus::Suspended, 'suspended_at' => now()]);
    }

    public function university(): static
    {
        return $this->state(fn (array $attributes) => ['type' => InstitutionType::University]);
    }
}
