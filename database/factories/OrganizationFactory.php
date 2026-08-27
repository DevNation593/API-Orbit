<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->company(), 'email' => fake()->companyEmail(), 'phone' => fake()->phoneNumber(), 'custom_fields' => []];
    }
}
