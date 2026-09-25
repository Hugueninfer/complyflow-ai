<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function me(Request $request, CurrentOrganization $currentOrganization): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organizations()->where('organizations.id', $currentOrganization->id())->firstOrFail();
        $role = Role::query()->with('permissions')->findOrFail($organization->pivot->role_id);
        $demo = DemoSession::query()->where('user_id', $user->getKey())->first();

        return response()->json(['data' => [
            'user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization->public_id, 'name' => $organization->name],
            'role' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
            'demo' => $demo === null ? null : [
                'id' => $demo->public_id,
                'organization_id' => $organization->public_id,
                'expires_at' => $demo->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'quotas' => [
                    'suppliers' => $demo->supplier_quota,
                    'analyses' => $demo->analysis_quota,
                    'storage_bytes' => $demo->storage_quota_bytes,
                ],
            ],
        ]]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', function (string $attribute, string $value, Closure $fail): void {
                // Older identities may predate normalization. Do not create an alias account.
                if (User::query()->whereRaw('lower(trim(email)) = ?', [$value])->exists()) {
                    $fail('This email is already registered.');
                }
            }],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'organization_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            [$user, $organization] = DB::transaction(function () use ($validated): array {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);
                $organization = Organization::query()->create([
                    'name' => $validated['organization_name'],
                    'slug' => Str::slug($validated['organization_name']).'-'.Str::lower(Str::random(8)),
                ]);
                $owner = Role::query()->where('name', 'owner')->firstOrFail();

                $organization->users()->attach($user, ['role_id' => $owner->id]);

                return [$user, $organization];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A second request can pass validation before the first commits. The
            // existing unique email constraint is the final arbiter for new accounts.
            if (User::query()->whereRaw('lower(trim(email)) = ?', [$validated['email']])->exists()) {
                throw ValidationException::withMessages(['email' => ['This email is already registered.']]);
            }
            throw $exception;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email],
                'organization' => ['id' => $organization->public_id, 'name' => $organization->name],
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt([
            'password' => $credentials['password'],
            fn (Builder $query) => $query->whereRaw('lower(trim(email)) = ?', [$credentials['email']]),
        ])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email],
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
