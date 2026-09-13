<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['finding_reviews', 'supplier_decisions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('idempotency_key', 128)->nullable();
                $table->char('request_hash', 64)->nullable();
                $table->unique(['organization_id', 'idempotency_key']);
            });
        }
        Schema::table('supplier_decisions', function (Blueprint $table) {
            $table->unique('analysis_run_id');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            // Immutable UUID snapshots keep hashes stable independently of identity records.
            $table->uuid('organization_public_id')->nullable();
            $table->uuid('actor_public_id')->nullable();
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('complyflow.purge_demo', true) = OLD.organization_id::text
                    AND EXISTS (SELECT 1 FROM demo_sessions WHERE organization_id = OLD.organization_id AND expires_at <= CURRENT_TIMESTAMP)
                THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'History records are append-only' USING ERRCODE = '23514';
            END;
            $$
            SQL);
        foreach (['finding_reviews', 'supplier_decisions', 'audit_logs'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()");
        }
    }

    public function down(): void
    {
        foreach (['finding_reviews', 'supplier_decisions', 'audit_logs'] as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}_immutable ON {$name}");
        }
        DB::statement('DROP FUNCTION IF EXISTS reject_history_mutation()');
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn(['organization_public_id', 'actor_public_id']));
        Schema::table('supplier_decisions', fn (Blueprint $table) => $table->dropUnique(['analysis_run_id']));
        foreach (['finding_reviews', 'supplier_decisions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'idempotency_key']);
                $table->dropColumn(['idempotency_key', 'request_hash']);
            });
        }
    }
};
