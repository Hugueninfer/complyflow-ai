<?php

namespace App\Console\Commands;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredDemos extends Command
{
    protected $signature = 'demo:purge-expired';

    protected $description = 'Purge expired demo tenants and their temporary users';

    public function handle(): int
    {
        $ids = DemoSession::query()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');
        $purged = 0;

        foreach ($ids as $id) {
            $wasPurged = DB::transaction(function () use ($id): bool {
                $demoSession = DemoSession::query()->lockForUpdate()->find($id);

                if ($demoSession === null || $demoSession->expires_at->isAfter(now())) {
                    return false;
                }

                Organization::withTrashed()->whereKey($demoSession->organization_id)->lockForUpdate()->first();
                // Transaction-local retention exception, also checked against expiry by the DB trigger.
                DB::select('select set_config(?, ?, true)', ['complyflow.purge_demo', (string) $demoSession->organization_id]);
                foreach (['supplier_decisions', 'finding_reviews', 'audit_logs'] as $table) {
                    DB::table($table)->where('organization_id', $demoSession->organization_id)->delete();
                }

                DB::table('document_blobs')
                    ->where('organization_id', $demoSession->organization_id)
                    ->delete();

                $organization = Organization::withTrashed()->find($demoSession->organization_id);

                if ($organization !== null) {
                    $organization->forceDelete();
                } else {
                    $demoSession->delete();
                }

                User::query()->whereKey($demoSession->user_id)->delete();

                return true;
            });

            $purged += (int) $wasPurged;
        }

        $this->info("Purged {$purged} expired demo session(s).");

        return self::SUCCESS;
    }
}
