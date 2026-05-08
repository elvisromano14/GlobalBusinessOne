<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class B1SessionMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!session()->has('b1_token')) {
            return redirect()->route('login')->withErrors(['error' => 'Debe iniciar sesión para acceder.']);
        }

        return $next($request);
    }
}
