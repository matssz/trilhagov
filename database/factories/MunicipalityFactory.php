<?php

namespace Database\Factories;

use App\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipality>
 */
class MunicipalityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city(),
            'state' => fake()->randomElement(['SP', 'MG', 'PR', 'BA', 'PE']),
            'cnpj' => fake()->unique()->numerify('##############'),
            'ibge_code' => fake()->unique()->numerify('#######'),
        ];
    }
}
