<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => __('api.errors.unauthenticated')], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json(['message' => __('api.errors.forbidden')], 403);
        }

        return $next($request);
    }
}
