<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\DemoSession;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

        return response()->json(['data' => $this->data($supplier)], 201);
    }

    public function show(string $supplier): JsonResponse
    {
        $model = $this->resolve($supplier);
        Gate::authorize('view', $model);

        return response()->json(['data' => $this->data($model)]);
    }

    public function update(StoreSupplierRequest $request, string $supplier): JsonResponse
    {
        $model = $this->resolve($supplier);
        Gate::authorize('update', $model);
        $model->update($request->safe()->only(['name', 'tax_id', 'risk_level']));

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
