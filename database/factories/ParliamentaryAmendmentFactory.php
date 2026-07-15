<?php

namespace Database\Factories;

use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParliamentaryAmendment>
 */
class ParliamentaryAmendmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'municipality_id' => Municipality::factory(),
            'created_by' => User::factory(),
            'reference' => fake()->unique()->numerify('EM-2026-####'),
            'fiscal_year' => now()->year,
            'government_sphere' => 'federal',
            'authorship_type' => 'individual',
            'transfer_type' => 'special',
            'author_name' => fake()->name(),
            'author_party' => fake()->randomElement(['PSD', 'MDB', 'PL', 'PT']),
            'object' => fake()->sentence(8),
            'responsible_department' => 'Secretaria de Administração',
            'transferegov_code' => fake()->numerify('######'),
            'expected_amount' => fake()->randomFloat(2, 200000, 2000000),
            'received_amount' => null,
            'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
            'indicated_at' => now()->subMonth(),
            'execution_deadline' => now()->addMonths(6),
        ];
    }
}
