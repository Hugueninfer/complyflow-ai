<?php

namespace App\Services\Analysis;

use App\Models\RequirementSet;
use App\Models\Supplier;

final class AnalysisFingerprint
{
    public static function make(Supplier $supplier, RequirementSet $set, array $hashes): string
    {
        sort($hashes, SORT_STRING);

        return hash('sha256', json_encode([$supplier->organization_id, $supplier->public_id, $set->public_id, $set->version, $hashes], JSON_THROW_ON_ERROR));
    }
}
