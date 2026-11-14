<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API twin of EnsureAccountUsable: a deactivated user's token is revoked at once, and a user who must change the
 * temporary password can only read their profile, change the password or sign out.
 */
class EnsureApiAccountUsable
{
    private const ALLOWED_BEFORE_PASSWORD_CHANGE = ['api.me', 'api.change-password', 'api.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Your account is deactivated.', 'code' => 'deactivated'], 403);
        }
        if ($user && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_BEFORE_PASSWORD_CHANGE)) {
            return response()->json(['message' => 'Please set a new password to continue.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
