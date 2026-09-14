<?php

namespace Database\Factories;

use App\Models\DemoSession;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSessionFactory extends Factory
{
    protected $model = DemoSession::class;

    public function definition(): array
    {
        return ['organization_id' => OrganizationFactory::new(), 'user_id' => UserFactory::new(), 'token_hash' => hash('sha256', Str::random(64)), 'supplier_quota' => 10, 'analysis_quota' => 3, 'storage_quota_bytes' => 15728640, 'expires_at' => now()->addHours(24)];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DemoSession $session): void {
            DB::table('organization_user')->insert(['organization_id' => $session->organization_id, 'user_id' => $session->user_id, 'role_id' => Role::where('name', 'reviewer')->sole()->id, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
