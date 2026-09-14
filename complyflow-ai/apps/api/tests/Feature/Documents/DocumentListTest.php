<?php

namespace Tests\Feature\Documents;

use Illuminate\Support\Facades\DB;

class DocumentListTest extends DocumentTestCase
{
    public function test_list_is_paginated_deterministic_and_metadata_only(): void
    {
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf(marker: (string) $i)])
                ->assertCreated()->json('data.id');
        }
        $this->getJson($this->url())->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.id', $ids[25])->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2)->assertJsonMissingPath('data.0.contents')
            ->assertJsonMissingPath('data.0.original_name')->assertJsonMissingPath('data.0.supplier_id')
            ->assertJsonMissingPath('data.0.organization_id');
        $this->getJson($this->url().'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[0]);
    }

    public function test_list_rejects_foreign_supplier_internal_id_and_missing_permission_even_when_empty(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs($this->analyst)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        $other = $this->supplier($this->organization());
        $this->getJson($this->url($other))->assertNotFound();
        $this->getJson('/api/v1/suppliers/'.$this->supplier->id.'/documents')->assertNotFound();
        $sameTenant = $this->supplier($this->organization);
        $this->postJson($this->url($sameTenant), ['file' => $this->pdf()])->assertCreated();
        $this->getJson($this->url())->assertJsonCount(0, 'data');
        DB::table('role_permission')->delete();
        $this->getJson($this->url())->assertForbidden();
    }
}
