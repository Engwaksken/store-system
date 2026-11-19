<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\DeviceSignInKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /**
     * Fingerprint / face sign-in: the app sends the device key it unlocked with the phone's biometrics and gets a new
     * token. 10 attempts per minute per IP. A wrong, removed or revoked key → 401 `biometric_key_invalid`.
     */
    public function biometricLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:200', 'device_name' => 'required|string|max:100']);
        $limit = 'api-biometric-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limit, 10)) {
            return response()->json(['message' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($limit).' seconds.'], 429);
        }
        $key = DeviceSignInKey::findByPlain($data['key']);
        if (! $key) {
            RateLimiter::hit($limit, 60);

            return response()->json([
                'message' => 'Fingerprint sign-in is no longer set up for this phone. Sign in with your password.',
                'code' => 'biometric_key_invalid',
            ], 401);
        }
        $user = $key->user;
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is deactivated.', 'code' => 'deactivated'], 403);
        }
        $key->forceFill(['last_used_at' => now()])->save();
        $token = $user->createToken($data['device_name'])->plainTextToken;
        ActivityLog::record('auth.api_login', "{$user->name} signed in on the mobile app with fingerprint / face ({$data['device_name']})", $user);

        return response()->json(['token' => $token, 'user' => Present::user($user)]);
    }

    /** Turns on fingerprint sign-in for this phone. Returns the device key once; the app stores it in its keystore. */
    public function createBiometricKey(Request $request): JsonResponse
    {
        $data = $request->validate(['device_name' => 'required|string|max:100', 'replace_key' => 'nullable|string|max:200']);
        $user = $request->user();
        $old = DeviceSignInKey::findByPlain($data['replace_key'] ?? null);
        if ($old && $old->user_id === $user->id) {
            $old->delete();
        }
        [, $plain] = DeviceSignInKey::issue($user, $data['device_name']);
        ActivityLog::record('auth.device_key_added', "{$user->name} turned on fingerprint sign-in on {$data['device_name']}", $user);

        return response()->json(['key' => $plain], 201);
    }

    /** Turns fingerprint sign-in off for a phone. Holding the key is the proof, so no token is needed. Always 204. */
    public function revokeBiometricKey(Request $request)
    {
        $request->validate(['key' => 'required|string|max:200']);
        DeviceSignInKey::findByPlain($request->input('key'))?->delete();

        return response()->noContent();
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

    /** The signed-in user updates their own name, e-mail and phone. Changing the e-mail needs the current password. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
        ]);
        $data['email'] = Str::lower(trim($data['email']));
        if ($data['email'] !== Str::lower($user->email)) {
            $request->validate(['current_password' => 'required|current_password:sanctum'], [
                'current_password.required' => 'Enter your current password to change your e-mail.',
            ]);
        }
        $user->fill(['name' => trim($data['name']), 'email' => $data['email'], 'phone' => trim($data['phone'] ?? '') ?: null]);
        if ($user->isDirty()) {
            $changed = implode(', ', array_keys($user->getDirty()));
            $user->save();
            ActivityLog::record('auth.profile_updated', "{$user->name} updated their profile ({$changed}) on the mobile app", $user);
        }

        return response()->json(['user' => Present::user($user->fresh())]);
    }

    /** Profile photo: JPG / PNG / WebP up to 3 MB, stored on the public disk (avatars/). */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:3072|dimensions:max_width=6000,max_height=6000']);
        $user = $request->user();
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $request->file('avatar')->store('avatars', 'public')])->save();
        if ($old) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }
        ActivityLog::record('auth.avatar_updated', "{$user->name} changed their profile photo", $user);

        return response()->json(['user' => Present::user($user->fresh())]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return response()->json(['user' => Present::user($user->fresh())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|current_password:sanctum',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
            'keep_biometric_key' => 'nullable|string|max:200',
        ]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->password), 'must_change_password' => false])->save();
        // Sign out every other device; this one keeps its token and, if sent, its fingerprint sign-in key.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        $keep = DeviceSignInKey::findByPlain($request->input('keep_biometric_key'));
        $user->deviceSignInKeys()->when($keep && $keep->user_id === $user->id, fn ($q) => $q->whereKeyNot($keep->id))->delete();
        ActivityLog::record('auth.password_changed', $user->name.' changed password (mobile app)', $user);

        return response()->json(['user' => Present::user($user->fresh())]);
    }
}
