<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestampsTz();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['organization_id', 'user_id']);
            $table->index(['user_id', 'organization_id']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high'])->default('medium');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'name']);
            $table->unique(['organization_id', 'tax_id']);
        });

        Schema::create('requirement_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('requirement_sets')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'name', 'version']);
        });

        Schema::create('requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_set_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->string('category');
            $table->decimal('weight', 6, 3)->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->text('evaluation_text');
            $table->boolean('is_required')->default(true);
            $table->timestampsTz();
            $table->index(['organization_id', 'requirement_set_id']);
            $table->unique(['requirement_set_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
        Schema::dropIfExists('requirement_sets');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organizations');
    }
};
