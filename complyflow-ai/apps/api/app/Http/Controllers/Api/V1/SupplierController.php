<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\AnalysisRun;
use App\Models\DemoSession;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Supplier::class);
        $suppliers = Supplier::query()->forCurrentOrganization()->orderBy('name')->get();

        return response()->json(['data' => $suppliers->map(fn (Supplier $supplier) => $this->data($supplier))]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        Gate::authorize('create', Supplier::class);

        try {
            $supplier = DB::transaction(function () use ($request): Supplier {
                $demo = DemoSession::query()
                    ->where('user_id', $request->user()->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($demo !== null && $demo->suppliers_used >= $demo->supplier_quota) {
                    abort(429, 'Demo quota exceeded.');
                }

                $supplier = Supplier::query()->create($request->safe()->only([
                    'name', 'tax_id', 'risk_level',
                ]));

                $demo?->increment('suppliers_used');

                return $supplier;
            });
        } catch (QueryException $exception) {
            $this->throwTaxIdConflict($exception);
        }

        return response()->json(['data' => $this->data($supplier)], 201);
    }

    public function show(string $supplier): JsonResponse
    {
        $model = $this->resolve($supplier);
        Gate::authorize('view', $model);

        $data = $this->data($model);
        if (Gate::allows('viewAny', AnalysisRun::class)) {
            $latest = AnalysisRun::forCurrentOrganization()->where('supplier_id', $model->id)->orderByDesc('created_at')->orderByDesc('id')->first();
            $data['latest_analysis'] = $latest ? ['id' => $latest->public_id, 'status' => $latest->status] : null;
        }

        return response()->json(['data' => $data]);
    }

    public function update(StoreSupplierRequest $request, string $supplier): JsonResponse
    {
        $model = $this->resolve($supplier);
        Gate::authorize('update', $model);
        try {
            $model->update($request->safe()->only(['name', 'tax_id', 'risk_level']));
        } catch (QueryException $exception) {
            $this->throwTaxIdConflict($exception);
        }

        return response()->json(['data' => $this->data($model->refresh())]);
    }

    public function destroy(string $supplier): JsonResponse
    {
        $model = $this->resolve($supplier);
        Gate::authorize('delete', $model);
        $model->delete();

        return response()->json(null, 204);
    }

    private function resolve(string $publicId): Supplier
    {
        return Supplier::query()->wherePublicIdForCurrentOrganization($publicId)->firstOrFail();
    }

    private function throwTaxIdConflict(QueryException $exception): never
    {
        if (! $this->isUniqueViolation($exception)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'tax_id' => ['The tax ID has already been taken in this organization.'],
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }

    /** @return array<string, mixed> */
    private function data(Supplier $supplier): array
    {
        return [
            'id' => $supplier->public_id,
            'name' => $supplier->name,
            'tax_id' => $supplier->tax_id,
            'risk_level' => $supplier->risk_level,
        ];
    }
}
