<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PipelineStage> */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    public function definition(): array
    {
        return ['pipeline_id' => Pipeline::factory(), 'name' => fake()->word(), 'position' => 0, 'probability' => 0, 'is_won' => false, 'is_lost' => false];
    }
}
