<?php

namespace App\Http\Middleware;

use App\Models\DemoSession;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $demoSession = DemoSession::query()->where('user_id', $user->getKey())->first();

        if ($demoSession?->expires_at->lessThanOrEqualTo(now())) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Demo session expired.'], 401);
        }

        $organizationId = $user->organizations()
            ->orderBy('organization_user.created_at')
            ->value('organizations.id');

        abort_if($organizationId === null, 403, 'User does not belong to an organization.');

        $this->currentOrganization->set((int) $organizationId);

        try {
            return $next($request);
        } finally {
            $this->currentOrganization->clear();
        }
    }
}
