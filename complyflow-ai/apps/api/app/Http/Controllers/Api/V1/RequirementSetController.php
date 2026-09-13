<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequirementSetRequest;
use App\Models\Requirement;
use App\Models\RequirementSet;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RequirementSetController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', RequirementSet::class);
        $sets = RequirementSet::query()
            ->forCurrentOrganization()
            ->with('requirements')
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        return response()->json(['data' => $sets->map(fn (RequirementSet $set) => $this->data($set))]);
    }

    public function store(StoreRequirementSetRequest $request): JsonResponse
    {
        Gate::authorize('create', RequirementSet::class);

        try {
            $set = DB::transaction(function () use ($request): RequirementSet {
                $set = RequirementSet::query()->create([
                    'name' => $request->validated('name'),
                    'version' => 1,
                    'status' => 'draft',
                ]);
                $this->replaceRequirements($set, $request->validated('requirements'));

                return $set;
            });
        } catch (QueryException $exception) {
            $this->throwNameConflict($exception);
        }

        return response()->json(['data' => $this->data($set->load('requirements'))], 201);
    }

    public function show(string $requirementSet): JsonResponse
    {
        $set = $this->resolve($requirementSet);
        Gate::authorize('view', $set);

        return response()->json(['data' => $this->data($set->load('requirements'))]);
    }

    public function update(StoreRequirementSetRequest $request, string $requirementSet): JsonResponse
    {
        try {
            $set = DB::transaction(function () use ($request, $requirementSet): RequirementSet {
                $set = $this->resolve($requirementSet, lock: true);
                Gate::authorize('update', $set);

                if ($set->status !== 'draft') {
                    abort(409, 'Published requirement sets are immutable.');
                }

                if ($request->has('name')) {
                    $set->update(['name' => $request->validated('name')]);
                }

                if ($request->has('requirements')) {
                    $this->replaceRequirements($set, $request->validated('requirements'));
                }

                return $set;
            });
        } catch (QueryException $exception) {
            $this->throwNameConflict($exception);
        }

        return response()->json(['data' => $this->data($set->load('requirements'))]);
    }

    public function publish(string $requirementSet): JsonResponse
    {
        $set = DB::transaction(function () use ($requirementSet): RequirementSet {
            $set = $this->resolve($requirementSet, lock: true);
            Gate::authorize('publish', $set);

            if ($set->status !== 'draft') {
                abort(409, 'Only draft requirement sets can be published.');
            }

            $set->update(['status' => 'published', 'published_at' => now()]);

            return $set;
        });

        return response()->json(['data' => $this->data($set->load('requirements'))]);
    }

    public function createVersion(string $requirementSet): JsonResponse
    {
        try {
            $clone = DB::transaction(function () use ($requirementSet): RequirementSet {
                $source = $this->resolve($requirementSet, lock: true);
                Gate::authorize('createVersion', $source);

                if ($source->status !== 'published') {
                    abort(409, 'Only published requirement sets can be versioned.');
                }

                $root = $this->lineageRoot($source);
                $version = $source->version + 1;
                $exists = RequirementSet::query()
                    ->withTrashed()
                    ->forCurrentOrganization()
                    ->where('parent_id', $root->id)
                    ->where('version', $version)
                    ->exists();
                abort_if($exists, 409, 'The next requirement set version already exists.');

                $clone = RequirementSet::query()->create([
                    'parent_id' => $root->id,
                    'name' => $source->name,
                    'version' => $version,
                    'status' => 'draft',
                ]);

                foreach ($source->requirements()->get() as $requirement) {
                    $clone->requirements()->create($this->requirementAttributes($requirement));
                }

                return $clone;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            abort(409, 'The next requirement set version already exists.');
        }

        return response()->json(['data' => $this->data($clone->load('requirements'))], 201);
    }

    public function destroy(string $requirementSet): JsonResponse
    {
        DB::transaction(function () use ($requirementSet): void {
            $set = $this->resolve($requirementSet, lock: true);
            Gate::authorize('delete', $set);

            if ($set->status !== 'draft') {
                abort(409, 'Published requirement sets are immutable.');
            }

            $set->delete();
        });

        return response()->json(null, 204);
    }

    private function resolve(string $publicId, bool $lock = false): RequirementSet
    {
        $query = RequirementSet::query()->wherePublicIdForCurrentOrganization($publicId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function lineageRoot(RequirementSet $set): RequirementSet
    {
        while ($set->parent_id !== null) {
            $set = RequirementSet::query()
                ->forCurrentOrganization()
                ->whereKey($set->parent_id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $set;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }

    private function throwNameConflict(QueryException $exception): never
    {
        if (! $this->isUniqueViolation($exception)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'name' => ['The name and version have already been taken in this organization.'],
        ]);
    }

    /** @param list<array<string, mixed>> $requirements */
    private function replaceRequirements(RequirementSet $set, array $requirements): void
    {
        $set->requirements()->delete();

        foreach ($requirements as $attributes) {
            $set->requirements()->create($attributes);
        }
    }

    /** @return array<string, mixed> */
    private function requirementAttributes(Requirement $requirement): array
    {
        return [
            'code' => $requirement->code,
            'title' => $requirement->title,
            'category' => $requirement->category,
            'weight' => $requirement->weight,
            'position' => $requirement->position,
            'evaluation_text' => $requirement->evaluation_text,
            'is_required' => $requirement->is_required,
        ];
    }

    /** @return array<string, mixed> */
    private function data(RequirementSet $set): array
    {
        return [
            'id' => $set->public_id,
            'name' => $set->name,
            'version' => $set->version,
            'status' => $set->status,
            'published_at' => $set->published_at?->toISOString(),
            'requirements' => $set->requirements->map(fn (Requirement $requirement): array => [
                'id' => $requirement->public_id,
                ...$this->requirementAttributes($requirement),
            ])->values(),
        ];
    }
}
