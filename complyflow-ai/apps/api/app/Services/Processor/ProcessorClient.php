<?php

namespace App\Services\Processor;

use App\Data\Processor\Contract;
use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Analysis\AnalysisFingerprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use JsonException;

class ProcessorClient
{
    public function analyze(AnalysisRun $run): ProcessorResult
    {
        $secret = config('services.processor.secret');
        if (! is_string($secret) || $secret === '') {
            throw new ProcessorException('processor_not_configured');
        }
        $set = RequirementSet::forOrganization($run->organization_id)->whereKey($run->requirement_set_id)->where('status', 'published')->first();
        $supplier = Supplier::forOrganization($run->organization_id)->whereKey($run->supplier_id)->first();
        $requirements = $set?->requirements()
            ->forOrganization($run->organization_id)->get();
        $documents = Document::forOrganization($run->organization_id)->where('supplier_id', $run->supplier_id)
            ->whereIn('public_id', $run->document_ids ?? [])->orderBy('sha256')->get();
        if (! $supplier || ! $requirements?->count() || $documents->isEmpty() || $documents->count() !== count($run->document_ids ?? [])
            || ! hash_equals($run->document_set_hash, AnalysisFingerprint::make($supplier, $set, $documents->pluck('sha256')->all()))) {
            throw new ProcessorException('invalid_analysis_input');
        }
        foreach ($requirements as $requirement) {
            if ((float) $requirement->weight <= 0) {
                throw new ProcessorException('invalid_analysis_input');
            }
        }
        $payload = ['analysis_id' => $run->public_id, 'idempotency_key' => $run->idempotency_key,
            'requirements' => $requirements->map(fn ($requirement) => ['requirement_id' => $requirement->public_id, 'criterion' => $requirement->title,
                'category' => $requirement->category, 'weight' => (float) $requirement->weight, 'evaluation_text' => $requirement->evaluation_text])->all(),
            'documents' => $documents->map(function ($document) use ($run) {
                $contents = DocumentBlob::forOrganization($run->organization_id)->where('document_id', $document->id)->value('contents');
                if (is_resource($contents)) {
                    $contents = stream_get_contents($contents);
                }
                if (! is_string($contents) || ! hash_equals($document->sha256, hash('sha256', $contents))) {
                    throw new ProcessorException('invalid_analysis_input');
                }

                return ['document_id' => $document->public_id, 'sha256' => $document->sha256, 'content_base64' => base64_encode($contents)];
            })->all()];
        $signed = new SignedProcessorRequest(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret, (string) now()->timestamp, (string) Str::uuid());
        try {
            $response = $signed->send(rtrim(config('services.processor.url'), '/').'/v1/analyze');
        } catch (ConnectionException) {
            throw new ProcessorException('processor_unavailable', true);
        }
        if ($response->status() !== 200) {
            $configurationFailure = $response->json('detail') === 'provider_not_configured';
            throw new ProcessorException('processor_request_failed', ! $configurationFailure && (in_array($response->status(), [408, 429], true) || $response->serverError()));
        }
        try {
            $result = ProcessorResult::fromJson($response->body());
        } catch (JsonException) {
            throw new ProcessorException('invalid_processor_result');
        }
        Contract::check($result->analysisId === $run->public_id);
        $this->sameIds($requirements->pluck('public_id')->all(), array_column($result->findings, 'requirementId'));
        $this->sameIds($documents->pluck('public_id')->all(), array_column($result->processedDocuments, 'document_id'));

        return $result;
    }

    private function sameIds(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        Contract::check($expected === $actual);
    }
}
