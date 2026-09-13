<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
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
