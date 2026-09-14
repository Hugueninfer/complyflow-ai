<?php

namespace App\Services\Documents;

use App\Models\DemoSession;
use App\Models\Document;
use App\Models\Supplier;
use App\Rules\ValidPdfSignature;
use App\Support\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;

class StorePdf
{
    public function handle(Supplier $supplier, UploadedFile $file): Document
    {
        Validator::make(['file' => $file], ['file' => ['required', new ValidPdfSignature]])->validate();
        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $size = fstat($stream)['size'];
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $sha256 = hash_final($hash);

            return DB::transaction(function () use ($supplier, $stream, $size, $sha256): Document {
                // Stable lock order: demo first (quota across suppliers), then supplier (deduplication).
                $demo = DemoSession::query()->where('organization_id', app(CurrentOrganization::class)->id())
                    ->lockForUpdate()->first();
                abort_if($demo?->expires_at->lessThanOrEqualTo(now()), 401, 'Demo session expired.');
                $supplier = Supplier::query()->wherePublicIdForCurrentOrganization($supplier->public_id)
                    ->lockForUpdate()->firstOrFail();
                $existing = Document::query()->forCurrentOrganization()
                    ->where('supplier_id', $supplier->id)->where('sha256', $sha256)->first();

                if ($existing !== null) {
                    return $existing;
                }

                if ($demo !== null && $demo->storage_used_bytes + $size > $demo->storage_quota_bytes) {
                    throw new DemoStorageQuotaExceeded;
                }

                $name = Str::uuid().'.pdf';
                $document = Document::query()->create([
                    'supplier_id' => $supplier->id,
                    // Retain no client filename, including in database exception bindings.
                    'original_name' => $name,
                    'storage_name' => $name,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'status' => 'uploaded',
                ]);
                rewind($stream);
                $this->writeBlob($document, $stream);
                $demo?->increment('storage_used_bytes', $size);

                return $document;
            }, 3);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function writeBlob(Document $document, $stream): void
    {
        // Bind a stream as BYTEA. Bypass query listeners/logging of sensitive blob bindings.
        try {
            $statement = DB::connection()->getPdo()->prepare(
                'INSERT INTO document_blobs (public_id, organization_id, document_id, contents, created_at, updated_at)
                 VALUES (:public_id, :organization_id, :document_id, :contents, :created_at, :updated_at)',
            );
            $statement->bindValue(':public_id', (string) Str::uuid());
            $statement->bindValue(':organization_id', $document->organization_id, PDO::PARAM_INT);
            $statement->bindValue(':document_id', $document->id, PDO::PARAM_INT);
            $statement->bindParam(':contents', $stream, PDO::PARAM_LOB);
            $statement->bindValue(':created_at', now()->toDateTimeString());
            $statement->bindValue(':updated_at', now()->toDateTimeString());
            $statement->execute();
        } catch (PDOException) {
            // Do not attach the original exception: PostgreSQL details may contain binary input.
            throw new RuntimeException('Unable to persist PDF contents.');
        }
    }
}
