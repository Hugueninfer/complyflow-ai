<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_name')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->enum('status', ['uploaded', 'processing', 'ready', 'failed'])->default('uploaded');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'supplier_id']);
            $table->unique(['organization_id', 'sha256']);
        });

        Schema::create('document_blobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->binary('contents');
            $table->timestampsTz();
            $table->index(['organization_id', 'document_id']);
        });

        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->longText('text');
            $table->timestampsTz();
            $table->index(['organization_id', 'document_id']);
            $table->unique(['document_id', 'page_number']);
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_page_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->timestampsTz();
            $table->index(['organization_id', 'document_id']);
            $table->index(['document_page_id', 'start_offset']);
        });
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(384) NULL');

        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_set_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key');
            $table->char('document_set_hash', 64);
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
            $table->unique(
                ['organization_id', 'supplier_id', 'requirement_set_id', 'document_set_hash', 'idempotency_key'],
                'analysis_runs_tenant_idempotency_unique',
            );
        });

        Schema::create('analysis_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['met', 'partial', 'missing', 'inconclusive']);
            $table->text('justification');
            $table->decimal('confidence', 5, 4);
            $table->text('search_summary')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'analysis_run_id']);
            $table->unique(['analysis_run_id', 'requirement_id']);
        });
        DB::statement(
            'ALTER TABLE analysis_findings ADD CONSTRAINT analysis_findings_confidence_check CHECK (confidence >= 0 AND confidence <= 1)',
        );

        Schema::create('finding_citations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_page_id')->constrained()->restrictOnDelete();
            $table->text('excerpt');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->timestampsTz();
            $table->index(['organization_id', 'analysis_finding_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_citations');
        Schema::dropIfExists('analysis_findings');
        Schema::dropIfExists('analysis_runs');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('document_pages');
        Schema::dropIfExists('document_blobs');
        Schema::dropIfExists('documents');
    }
};
