<?php

namespace Database\Factories;

use App\Models\RequirementSet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RequirementSetFactory extends Factory
{
    protected $model = RequirementSet::class;

    public function definition(): array
    {
        return ['organization_id' => OrganizationFactory::new(), 'name' => 'Checklist fictício '.Str::uuid(), 'version' => 1, 'status' => 'draft'];
    }
}
