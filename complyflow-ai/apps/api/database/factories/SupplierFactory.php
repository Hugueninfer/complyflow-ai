<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return ['organization_id' => OrganizationFactory::new(), 'name' => 'Fornecedor fictício', 'tax_id' => null, 'risk_level' => 'medium'];
    }
}
