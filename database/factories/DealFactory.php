<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deal> */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();
        $pipeline = Pipeline::factory()->for($tenant);

        return ['tenant_id' => $tenant, 'pipeline_id' => $pipeline, 'stage_id' => PipelineStage::factory()->for($pipeline, 'pipeline'), 'name' => fake()->sentence(3), 'value' => fake()->randomFloat(2, 0, 100000), 'currency' => 'USD', 'status' => 'open', 'custom_fields' => []];
    }
}
