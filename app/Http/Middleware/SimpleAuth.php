<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SimpleAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('polymarket.dashboard_password');

        // No password configured — skip auth entirely (dev mode).
        if (empty($password)) {
            return $next($request);
        }

        if ($request->session()->get('authenticated') === true) {
            return $next($request);
        }

        // API requests get a 401 JSON response.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Web requests redirect to login.
        return redirect()->route('login');
    }
}
