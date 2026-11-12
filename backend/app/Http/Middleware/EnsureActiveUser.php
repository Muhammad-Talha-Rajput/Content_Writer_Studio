<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active
            || (int) $request->session()->get('auth_version', -1) !== $request->user()->auth_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your session has ended. Please sign in again.'], 401);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
