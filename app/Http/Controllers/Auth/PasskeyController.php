<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\DeviceSignInKey;
use App\Models\Passkey;
use App\Models\User;
use App\Support\WebAuthn\WebAuthn;
use App\Support\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Fingerprint / face sign-in on the web with passkeys (WebAuthn).
 * Passkeys are discoverable, so signing in needs no e-mail: the browser lists the passkeys saved for this site.
 * Challenges live in the session for five minutes and are used once.
 */
class PasskeyController extends Controller
{
    private const TTL = 300;

    private const TIMEOUT_MS = 120000;

    // ---------------------------------------------------------------- sign in (guests)

    public function loginOptions(Request $request): JsonResponse
    {
        $webauthn = WebAuthn::fromConfig();
        $challenge = WebAuthn::challenge();
        $request->session()->put('passkey.login', ['challenge' => $challenge, 'expires' => time() + self::TTL]);

        return response()->json([
            'challenge' => $challenge,
            'rpId' => $webauthn->rpId,
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['credential' => 'required|array', 'remember' => 'sometimes|boolean']);
        $pending = $request->session()->pull('passkey.login');
        $fail = fn (string $msg = 'Fingerprint sign-in did not work. Try again or use your password.') => response()->json(['message' => $msg], 422);
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            return $fail('The sign-in request expired. Try again.');
        }

        $credential = $data['credential'];
        $passkey = is_string($credential['id'] ?? null) ? Passkey::with('user')->where('credential_id', $credential['id'])->first() : null;
        if (! $passkey) {
            return $fail('This passkey is not registered here. It may have been removed; sign in with your password.');
        }

        try {
            $count = WebAuthn::fromConfig()->verifyAssertion($credential, $pending['challenge'], $passkey->public_key, $passkey->sign_count);
            $handle = $credential['response']['userHandle'] ?? null;
            if (is_string($handle) && $handle !== '' && WebAuthn::unb64($handle) !== (string) $passkey->user_id) {
                throw new WebAuthnException('User handle does not match.');
            }
        } catch (WebAuthnException $e) {
            Log::notice('Passkey sign-in rejected: '.$e->getMessage(), ['passkey_id' => $passkey->id]);

            return $fail();
        }

        /** @var User $user */
        $user = $passkey->user;
        if (! $user->is_active) {
            return $fail('Your account is deactivated.');
        }

        $passkey->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();
        Auth::login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        ActivityLog::record('auth.login', "{$user->name} logged in with a passkey ({$passkey->name})", $user);

        return response()->json(['redirect' => redirect()->intended(route('dashboard'))->getTargetUrl()]);
    }

    // ---------------------------------------------------------------- manage (signed in)

    /** Adding a passkey needs the current password, so a borrowed session cannot plant a permanent way in. */
    public function registerOptions(Request $request): JsonResponse
    {
        $request->validate(['current_password' => 'required|current_password'], [
            'current_password.current_password' => 'The password is incorrect.',
        ]);
        $user = $request->user();
        $webauthn = WebAuthn::fromConfig();
        $challenge = WebAuthn::challenge();
        $request->session()->put('passkey.register', ['challenge' => $challenge, 'expires' => time() + self::TTL]);

        return response()->json([
            'challenge' => $challenge,
            'rp' => ['id' => $webauthn->rpId, 'name' => AppSetting::get('company_name') ?: config('app.name')],
            'user' => ['id' => WebAuthn::b64((string) $user->id), 'name' => $user->email, 'displayName' => $user->name],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => WebAuthn::ALG_ES256],
                ['type' => 'public-key', 'alg' => WebAuthn::ALG_RS256],
            ],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => self::TIMEOUT_MS,
            'excludeCredentials' => $user->passkeys()->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['credential' => 'required|array', 'name' => 'nullable|string|max:100']);
        $pending = $request->session()->pull('passkey.register');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            return response()->json(['message' => 'The request expired. Try again.'], 422);
        }

        try {
            $result = WebAuthn::fromConfig()->verifyRegistration($data['credential'], $pending['challenge']);
        } catch (WebAuthnException $e) {
            Log::notice('Passkey registration rejected: '.$e->getMessage(), ['user_id' => $request->user()->id]);

            return response()->json(['message' => 'The passkey could not be added: '.$e->getMessage()], 422);
        }
        if (Passkey::where('credential_id', $result['credential_id'])->exists()) {
            return response()->json(['message' => 'This passkey is already registered.'], 422);
        }

        $user = $request->user();
        $name = trim($data['name'] ?? '') ?: self::guessName($request->userAgent());
        $user->passkeys()->create([...$result, 'name' => $name]);
        ActivityLog::record('auth.passkey_added', "{$user->name} added a passkey ({$name})", $user);
        $request->session()->flash('status', 'Fingerprint / face sign-in added for '.$name.'.');

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Passkey $passkey)
    {
        abort_unless($passkey->user_id === $request->user()->id, 404);
        $passkey->delete();
        ActivityLog::record('auth.passkey_removed', "{$request->user()->name} removed a passkey ({$passkey->name})", $request->user());

        return back()->with('status', 'Passkey removed. Also delete it from the device\'s saved passkeys if you no longer use it.');
    }

    public function destroyDevice(Request $request, DeviceSignInKey $key)
    {
        abort_unless($key->user_id === $request->user()->id, 404);
        $key->delete();
        ActivityLog::record('auth.device_key_removed', "{$request->user()->name} turned off fingerprint sign-in for {$key->name}", $request->user());

        return back()->with('status', 'Fingerprint sign-in turned off for '.$key->name.'.');
    }

    private static function guessName(?string $ua): string
    {
        $ua = (string) $ua;
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'This device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "{$os} · {$browser}" : $os;
    }
}
