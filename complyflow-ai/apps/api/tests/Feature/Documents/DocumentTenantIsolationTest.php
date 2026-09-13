<?php

namespace Tests\Feature\Documents;

use Illuminate\Support\Facades\DB;

class DocumentTenantIsolationTest extends DocumentTestCase
{
    public function test_upload_requires_authentication_and_document_upload_permission(): void
    {
        $this->postJson($this->url(), ['file' => $this->pdf()])->assertUnauthorized();
        $reviewer = $this->user($this->organization, 'reviewer');
        $this->actingAs($reviewer)->postJson($this->url(), ['file' => $this->pdf()])->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_supplier_resolution_rejects_foreign_uuid_and_internal_id_before_validation(): void
    {
        $foreign = $this->supplier($this->organization());
        $this->actingAs($this->analyst)->postJson($this->url($foreign), ['file' => $this->pdf()])->assertNotFound();
        $this->postJson($this->url($foreign), [])->assertNotFound();
        $this->postJson('/api/v1/suppliers/'.$this->supplier->id.'/documents', ['file' => $this->pdf()])->assertNotFound();
    }

    public function test_document_metadata_is_tenant_scoped_and_never_exposes_internal_ids_or_bytes(): void
    {
        $id = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v1/documents/'.$id)->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.supplier_id')
            ->assertJsonMissingPath('data.contents')
            ->assertJsonMissingPath('data.original_name');
        $internalId = DB::table('documents')->where('public_id', $id)->value('id');
        $this->getJson('/api/v1/documents/'.$internalId)->assertNotFound();
        $foreign = $this->user($this->organization(), 'owner');
        $this->actingAs($foreign)->getJson('/api/v1/documents/'.$id)->assertNotFound();
    }

    public function test_document_read_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/documents/00000000-0000-4000-8000-000000000000')->assertUnauthorized();
        $id = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])
            ->assertCreated()->json('data.id');
        $reviewer = $this->user($this->organization, 'reviewer');
        $this->actingAs($reviewer)->getJson('/api/v1/documents/'.$id)->assertOk();
        DB::table('role_permission')->delete();
        $this->getJson('/api/v1/documents/'.$id)->assertForbidden();
    }

    public function test_same_bytes_in_another_tenant_create_distinct_document_and_blob(): void
    {
        $first = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])
            ->assertCreated()->json('data.id');
        $organization = $this->organization();
        $other = $this->supplier($organization);
        $second = $this->actingAs($this->user($organization, 'analyst'))
            ->postJson($this->url($other), ['file' => $this->pdf(), 'organization_id' => $this->organization->id])
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('documents', ['public_id' => $second, 'organization_id' => $organization->id]);
        $this->assertDatabaseCount('document_blobs', 2);
    }
}
