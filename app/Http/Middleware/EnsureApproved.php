<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isApproved()) {
            return Inertia::render('PendingApproval')->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
