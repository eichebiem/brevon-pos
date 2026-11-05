<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default state is a pop-up: the database allows only one permanent location
 * (D-058), so tests that need extra locations always create pop-ups.
 *
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'POP-'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => 'Pop-up '.fake()->city(),
            'location_type' => LocationType::PopUp,
            'city_municipality' => fake()->city(),
            'timezone' => 'Asia/Manila',
            'currency' => 'PHP',
            'is_active' => true,
        ];
    }

    public function permanent(): static
    {
        return $this->state(fn () => [
            'code' => 'BRV01',
            'name' => 'Brevon Doughnuts & Coffee — Main',
            'location_type' => LocationType::Permanent,
            'operating_starts_on' => null,
            'operating_ends_on' => null,
        ]);
    }

    public function popUp(?string $startsOn = null, ?string $endsOn = null): static
    {
        return $this->state(fn () => [
            'location_type' => LocationType::PopUp,
            'operating_starts_on' => $startsOn,
            'operating_ends_on' => $endsOn,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
