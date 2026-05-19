<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || auth()->user()->isAdmin !== 'true') {
            return response()->json([
                'status' => 'unauthorized',
                'message' => 'Anda tidak memiliki akses admin.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
