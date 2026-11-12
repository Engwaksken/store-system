<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Logs out deactivated users immediately and forces a password change after an admin reset. */
class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Your account is deactivated.']);
        }
        if ($user && $user->must_change_password && ! $request->routeIs('password.change*', 'logout')) {
            return redirect()->route('password.change')->with('status', 'Please set a new password to continue.');
        }

        return $next($request);
    }
}
