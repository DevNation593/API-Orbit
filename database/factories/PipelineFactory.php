<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pipeline> */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->unique()->words(2, true), 'description' => null, 'is_default' => false, 'active' => true];
    }
}
