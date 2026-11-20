<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class TokenIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? Sanctum::$personalAccessTokenModel::findToken($bearer) : null;

        if ($token && ($token->last_used_at ?? $token->created_at)->lte(now()->subHour())) {
            $token->delete();
            return response()->json(['success' => false, 'message' => 'Session expired due to inactivity. Please sign in again.'], 401);
        }

        if ($token && $token->tokenable?->status !== 'active') {
            $token->delete();
            return response()->json(['success' => false, 'message' => 'Account is not active.'], 401);
        }

        return $next($request);
    }
}
