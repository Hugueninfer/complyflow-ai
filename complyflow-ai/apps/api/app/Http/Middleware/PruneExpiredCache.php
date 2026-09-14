<?php

namespace App\Http\Middleware;

use App\Services\Maintenance\PruneExpiredApplicationCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PruneExpiredCache
{
    public function __construct(private PruneExpiredApplicationCache $cleanup) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->cleanup->run();

        return $next($request);
    }
}
