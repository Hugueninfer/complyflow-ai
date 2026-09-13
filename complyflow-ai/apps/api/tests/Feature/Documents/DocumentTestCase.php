<?php

namespace Tests\Feature\Documents;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class DocumentTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Supplier $supplier;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->organization = $this->organization();
        $this->supplier = $this->supplier($this->organization);
        $this->analyst = $this->user($this->organization, 'analyst');
    }

    protected function organization(): Organization
    {
        return Organization::query()->create(['name' => 'Document tenant', 'slug' => (string) Str::uuid()]);
    }

    protected function user(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role_id' => Role::query()->where('name', $role)->firstOrFail()->id]);

        return $user;
    }

    protected function supplier(Organization $organization): Supplier
    {
        app(CurrentOrganization::class)->set($organization);
        try {
            return Supplier::query()->create(['name' => 'Document supplier', 'risk_level' => 'medium']);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }

    protected function demo(): DemoSession
    {
        return DemoSession::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->analyst->id,
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'storage_quota_bytes' => 15728640,
            'expires_at' => now()->addHour(),
        ]);
    }

    protected function url(?Supplier $supplier = null): string
    {
        return '/api/v1/suppliers/'.($supplier ?? $this->supplier)->public_id.'/documents';
    }

    protected function pdf(string $name = 'private-contract.pdf', ?int $bytes = null, string $marker = 'a'): UploadedFile
    {
        $content = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%".$marker."\x00\xFF\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $bytes === null ? $content : str_pad($content, $bytes, ' '));
    }
}
