<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'organization_name' => ['required', 'string', 'max:255'],
        ]);

        [$user, $organization] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
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
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
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
}
