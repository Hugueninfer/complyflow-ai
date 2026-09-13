<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['met', 'partial', 'missing', 'inconclusive']);
            $table->text('justification');
            $table->text('notes')->nullable();
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'analysis_finding_id']);
        });

        Schema::create('supplier_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->enum('decision', ['approved', 'rejected', 'conditional']);
            $table->text('justification');
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'supplier_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->uuid('target_public_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64)->unique();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'target_type', 'target_public_id']);
        });

        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedSmallInteger('supplier_quota')->default(10);
            $table->unsignedSmallInteger('analysis_quota')->default(3);
            $table->unsignedBigInteger('storage_quota_bytes')->default(15728640);
            $table->unsignedSmallInteger('suppliers_used')->default(0);
            $table->unsignedSmallInteger('analyses_used')->default(0);
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sessions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('supplier_decisions');
        Schema::dropIfExists('finding_reviews');
    }
};
