<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    /** Issues a Sanctum token for the device. 10 attempts per minute per e-mail + IP. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'required|string|max:100']);
        $key = 'api-login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'], 429);
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        RateLimiter::clear($key);
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is deactivated.', 'code' => 'deactivated'], 403);
        }
        $token = $user->createToken($data['device_name'])->plainTextToken;
        ActivityLog::record('auth.api_login', "{$user->name} signed in on the mobile app ({$data['device_name']})", $user);

        return response()->json(['token' => $token, 'user' => Present::user($user)]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => Present::user($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|current_password:sanctum',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->password), 'must_change_password' => false])->save();
        // Sign out every other device; this one keeps its token.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        ActivityLog::record('auth.password_changed', $user->name.' changed password (mobile app)', $user);

        return response()->json(['user' => Present::user($user->fresh())]);
    }
}
