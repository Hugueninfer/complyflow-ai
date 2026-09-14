<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\StorePdf;
use App\Support\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentUploadTest extends DocumentTestCase
{
    public function test_rejects_pdf_extension_and_claimed_mime_with_non_pdf_contents(): void
    {
        $file = UploadedFile::fake()->createWithContent('fraude.pdf', '<script>alert(1)</script>');
        $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_blobs', 0);
    }

    public function test_rejects_pdf_signature_that_does_not_start_at_byte_zero(): void
    {
        $file = UploadedFile::fake()->createWithContent('padded.pdf', "\n%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));
        $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_requires_a_file(): void
    {
        $this->actingAs($this->analyst)->postJson($this->url(), [])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_native_php_upload_size_error_is_413(): void
    {
        $file = new UploadedFile('', 'private.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $file])->assertStatus(413);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_stores_exact_binary_pdf_in_postgres_and_generates_private_server_name(): void
    {
        $file = $this->pdf('confidential-client-name.pdf');
        $contents = file_get_contents($file->getRealPath());
        $response = $this->actingAs($this->analyst)->postJson($this->url(), [
            'file' => $file, 'storage_name' => '../../override.pdf', 'sha256' => str_repeat('0', 64),
        ])->assertCreated();
        $id = $response->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', $response->json('data.storage_name'));
        $response->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size_bytes', strlen($contents))
            ->assertJsonPath('data.sha256', hash('sha256', $contents))
            ->assertJsonPath('data.status', 'uploaded');
        $this->assertStringNotContainsString('confidential-client-name', $response->getContent());
        $document = DB::table('documents')->where('public_id', $id)->first();
        $this->assertSame($document->storage_name, $document->original_name);
        $this->assertSame($this->organization->id, $document->organization_id);
        $this->assertSame($this->supplier->id, $document->supplier_id);
        $blob = DB::table('document_blobs')->where('document_id', $document->id)->first();
        $this->assertSame($this->organization->id, $blob->organization_id);
        $this->assertSame($contents, is_resource($blob->contents) ? stream_get_contents($blob->contents) : $blob->contents);
        $this->assertSame('bytea', DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'document_blobs' AND column_name = 'contents'")->data_type);
    }

    public function test_accepts_exactly_five_mib(): void
    {
        $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf(bytes: 5242880)])
            ->assertCreated()->assertJsonPath('data.size_bytes', 5242880);
    }

    public function test_rejects_five_mib_plus_one_byte_with_413_and_no_storage_charge(): void
    {
        $demo = $this->demo();
        $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf(bytes: 5242881)])
            ->assertStatus(413);
        $this->assertSame(0, $demo->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_deduplicates_same_supplier_and_hash_even_with_different_client_name(): void
    {
        $first = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])
            ->assertCreated()->json('data.id');
        $this->postJson($this->url(), ['file' => $this->pdf('renamed.pdf')])
            ->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_blobs', 1);
    }

    public function test_does_not_deduplicate_between_suppliers(): void
    {
        $other = $this->supplier($this->organization);
        $first = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])
            ->assertCreated()->json('data.id');
        $second = $this->postJson($this->url($other), ['file' => $this->pdf()])->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('document_blobs', 2);
    }

    public function test_demo_accepts_fifteen_mib_total_then_rejects_addition_but_allows_duplicate(): void
    {
        $demo = $this->demo();
        foreach (['a', 'b', 'c'] as $marker) {
            $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf(bytes: 5242880, marker: $marker)])
                ->assertCreated();
        }
        $this->assertSame(15728640, $demo->fresh()->storage_used_bytes);
        $this->postJson($this->url(), ['file' => $this->pdf(marker: 'd')])->assertStatus(413);
        $this->postJson($this->url(), ['file' => $this->pdf(bytes: 5242880, marker: 'a')])->assertOk();
        $this->assertSame(15728640, $demo->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('documents', 3);
        $this->assertDatabaseCount('document_blobs', 3);
    }

    public function test_demo_quota_belongs_to_tenant_even_when_another_member_uploads(): void
    {
        $demo = $this->demo();
        $demo->update(['storage_used_bytes' => 15728640]);
        $other = $this->user($this->organization, 'analyst');
        $this->actingAs($other)->postJson($this->url(), ['file' => $this->pdf()])->assertStatus(413)
            ->assertJsonPath('code', 'demo_storage_quota_exceeded')
            ->assertExactJson(['code' => 'demo_storage_quota_exceeded', 'message' => 'Demo storage quota exceeded.']);
        $this->assertSame(15728640, $demo->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('document_blobs', 0);
    }

    public function test_blob_failure_rolls_back_metadata_and_quota_without_leaking_contents_in_exception_or_query_log(): void
    {
        $demo = $this->demo();
        DB::statement('ALTER TABLE document_blobs ADD CONSTRAINT reject_test_blob CHECK (document_id < 0)');
        DB::enableQueryLog();
        $file = $this->pdf('sensitive-customer.pdf');
        $contents = file_get_contents($file->getRealPath());
        app(CurrentOrganization::class)->set($this->organization);
        try {
            app(StorePdf::class)->handle($this->supplier, $file);
            $this->fail('The database constraint must reject the blob.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to persist PDF contents.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $log = serialize(DB::getQueryLog());
            $this->assertStringNotContainsString($contents, $log);
            $this->assertStringNotContainsString('sensitive-customer.pdf', $log);
        } finally {
            app(CurrentOrganization::class)->clear();
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(0, $demo->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_blobs', 0);
    }
}
