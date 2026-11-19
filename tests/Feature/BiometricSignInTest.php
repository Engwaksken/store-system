<?php

namespace Tests\Feature;

use App\Models\DeviceSignInKey;
use App\Models\Passkey;
use App\Models\User;
use App\Support\WebAuthn\WebAuthn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fingerprint / face sign-in: passkeys on the web (WebAuthn) and device keys for the mobile app. */
class BiometricSignInTest extends TestCase
{
    use RefreshDatabase;

    private const RP = 'store.test';

    private const ORIGIN = 'https://store.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.passkeys.rp_id' => self::RP, 'auth.passkeys.origins' => self::ORIGIN]);
    }

    // ---------------------------------------------------------------- web: passkeys

    public function test_a_user_adds_a_passkey_and_signs_in_with_it(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $authenticator = new FakeAuthenticator;

        // Adding needs the current password.
        $this->actingAs($user)->postJson(route('passkeys.options'), ['current_password' => 'wrong'])->assertUnprocessable();
        $options = $this->postJson(route('passkeys.options'), ['current_password' => 'secret123'])->assertOk()
            ->assertJsonPath('rp.id', self::RP)
            ->assertJsonPath('authenticatorSelection.userVerification', 'required')
            ->json();
        $this->assertSame((string) $user->id, WebAuthn::unb64($options['user']['id']));

        $this->postJson(route('passkeys.store'), ['name' => 'Office laptop', 'credential' => $authenticator->create($options['challenge'])])->assertOk();
        $passkey = $user->passkeys()->sole();
        $this->assertSame('Office laptop', $passkey->name);
        $this->get(route('password.change'))->assertOk()->assertSee('Office laptop');

        // Sign out, then sign in with the passkey (no e-mail typed).
        $this->post(route('logout'));
        $this->assertGuest();
        $this->get(route('login'))->assertSee('Sign in with fingerprint or face');
        $challenge = $this->postJson(route('passkey.login.options'))->assertOk()->assertJsonPath('rpId', self::RP)->json('challenge');
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, (string) $user->id)])
            ->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $passkey->fresh()->sign_count);
        $this->assertNotNull($passkey->fresh()->last_used_at);
    }

    public function test_passkey_sign_in_rejects_replays_wrong_origin_and_missing_user_verification(): void
    {
        $user = User::factory()->create();
        $authenticator = new FakeAuthenticator;
        $this->register($user, $authenticator);
        $this->post(route('logout'));

        // The challenge is used once.
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $credential = $authenticator->get($challenge, (string) $user->id);
        $this->postJson(route('passkey.login'), ['credential' => $credential])->assertOk();
        $this->post(route('logout'));
        $this->postJson(route('passkey.login'), ['credential' => $credential])->assertUnprocessable();

        // Phishing site.
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, null, origin: 'https://evil.test')])->assertUnprocessable();

        // Presence only, no fingerprint / face / PIN.
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, null, flags: 0x01)])->assertUnprocessable();

        // A counter that goes backwards (cloned authenticator).
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $authenticator->counter = 0;
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, null)])->assertUnprocessable();

        // Another key claiming the same credential id.
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $imposter = new FakeAuthenticator($authenticator->id);
        $imposter->counter = 100;
        $this->postJson(route('passkey.login'), ['credential' => $imposter->get($challenge, null)])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_sign_in_with_a_passkey_and_passkeys_can_be_removed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $authenticator = new FakeAuthenticator;
        $passkey = $this->register($user, $authenticator);

        $this->actingAs($other)->delete(route('passkeys.destroy', $passkey))->assertNotFound();
        $this->post(route('logout'));

        $user->forceFill(['is_active' => false])->save();
        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, null)])->assertUnprocessable();
        $this->assertGuest();

        $user->forceFill(['is_active' => true])->save();
        $this->actingAs($user)->delete(route('passkeys.destroy', $passkey))->assertRedirect();
        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_rsa_keys_are_accepted(): void
    {
        $user = User::factory()->create();
        $authenticator = new FakeAuthenticator(rsa: true);
        $this->register($user, $authenticator);
        $this->post(route('logout'));

        $challenge = $this->postJson(route('passkey.login.options'))->json('challenge');
        $this->postJson(route('passkey.login'), ['credential' => $authenticator->get($challenge, (string) $user->id)])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    private function register(User $user, FakeAuthenticator $authenticator): Passkey
    {
        $this->actingAs($user);
        $challenge = $this->postJson(route('passkeys.options'), ['current_password' => 'password'])->assertOk()->json('challenge');
        $this->postJson(route('passkeys.store'), ['credential' => $authenticator->create($challenge)])->assertOk();

        return $user->passkeys()->latest('id')->first();
    }

    // ---------------------------------------------------------------- mobile: device keys

    public function test_the_app_turns_on_fingerprint_sign_in_and_signs_in_with_the_device_key(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Pixel 8')->plainTextToken;

        $key = $this->postJson('/api/v1/me/biometric-key', ['device_name' => 'Pixel 8'], ['Authorization' => "Bearer {$token}"])
            ->assertCreated()->json('key');
        $this->assertMatchesRegularExpression('/^\d+\|.{64}$/', $key);
        $this->assertDatabaseHas('device_sign_in_keys', ['user_id' => $user->id, 'name' => 'Pixel 8']);
        $this->assertStringNotContainsString(explode('|', $key)[1], DeviceSignInKey::first()->secret_hash);

        $this->app['auth']->forgetGuards();
        $json = $this->postJson('/api/v1/auth/biometric-login', ['key' => $key, 'device_name' => 'Pixel 8'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->json();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$json['token']])->assertOk();

        // Wrong secret, or the right id with a wrong secret.
        $this->postJson('/api/v1/auth/biometric-login', ['key' => explode('|', $key)[0].'|nope', 'device_name' => 'x'])
            ->assertUnauthorized()->assertJsonPath('code', 'biometric_key_invalid');

        // Turning a key on again replaces the old one for this phone.
        $this->app['auth']->forgetGuards();
        $newKey = $this->postJson('/api/v1/me/biometric-key', ['device_name' => 'Pixel 8', 'replace_key' => $key], ['Authorization' => 'Bearer '.$json['token']])
            ->assertCreated()->json('key');
        $this->assertSame(1, $user->deviceSignInKeys()->count());
        $this->postJson('/api/v1/auth/biometric-login', ['key' => $key, 'device_name' => 'x'])->assertUnauthorized();

        // Turning it off.
        $this->postJson('/api/v1/auth/biometric-key/revoke', ['key' => $newKey])->assertNoContent();
        $this->assertSame(0, $user->deviceSignInKeys()->count());
    }

    public function test_device_keys_stop_working_for_deactivated_users_and_after_a_password_change(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        [, $here] = DeviceSignInKey::issue($user, 'This phone');
        [, $there] = DeviceSignInKey::issue($user, 'Lost phone');

        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/auth/biometric-login', ['key' => $here, 'device_name' => 'x'])->assertForbidden()->assertJsonPath('code', 'deactivated');
        $user->forceFill(['is_active' => true])->save();

        // Changing the password in the app keeps this phone's key and drops the others.
        $token = $user->createToken('This phone')->plainTextToken;
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123', 'keep_biometric_key' => $here,
        ], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame(['This phone'], $user->deviceSignInKeys()->pluck('name')->all());
        $this->postJson('/api/v1/auth/biometric-login', ['key' => $there, 'device_name' => 'x'])->assertUnauthorized();

        // Changing it on the web drops them all.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh())->put(route('password.change.update'), [
            'current_password' => 'newpass123', 'password' => 'another123', 'password_confirmation' => 'another123',
        ])->assertRedirect();
        $this->assertSame(0, $user->deviceSignInKeys()->count());
    }

    public function test_phones_are_listed_and_removable_on_the_security_page(): void
    {
        $user = User::factory()->create();
        [$key] = DeviceSignInKey::issue($user, 'Galaxy A54');
        [$foreign] = DeviceSignInKey::issue(User::factory()->create(), 'Not mine');

        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('Galaxy A54')->assertDontSee('Not mine');
        $this->delete(route('devices.destroy', $foreign))->assertNotFound();
        $this->delete(route('devices.destroy', $key))->assertRedirect();
        $this->assertDatabaseMissing('device_sign_in_keys', ['id' => $key->id]);
    }
}

/** A software authenticator that produces real WebAuthn responses (ES256 by default, or RS256). */
class FakeAuthenticator
{
    public string $id;

    public int $counter = 0;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(?string $id = null, private bool $rsa = false)
    {
        $this->id = $id ?? random_bytes(16);
        $options = $rsa
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = openssl_pkey_new($options);
        // Windows PHP builds (e.g. XAMPP) need the path to openssl.cnf to generate keys.
        foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY, 2).'/apache/conf/openssl.cnf'] as $cnf) {
            if ($key === false && is_file($cnf)) {
                $key = openssl_pkey_new([...$options, 'config' => $cnf]);
            }
        }
        if ($key === false) {
            throw new \RuntimeException('Could not generate a test key: '.openssl_error_string());
        }
        $this->key = $key;
    }

    public function create(string $challenge, string $origin = 'https://store.test', string $rpId = 'store.test'): array
    {
        $d = openssl_pkey_get_details($this->key);
        $cose = $this->rsa
            ? [1 => 3, 3 => -257, -1 => $d['rsa']['n'], -2 => $d['rsa']['e']]
            : [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
        $authData = hash('sha256', $rpId, true).chr(0x45).pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($this->id)).$this->id.self::cbor($cose);

        return [
            'id' => WebAuthn::b64($this->id), 'rawId' => WebAuthn::b64($this->id), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => WebAuthn::b64(json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $origin])),
                'attestationObject' => WebAuthn::b64(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)])),
            ],
        ];
    }

    public function get(string $challenge, ?string $userHandle, string $origin = 'https://store.test', int $flags = 0x05): array
    {
        $this->counter++;
        $authData = hash('sha256', 'store.test', true).chr($flags).pack('N', $this->counter);
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
        openssl_sign($authData.hash('sha256', $clientData, true), $sig, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => WebAuthn::b64($this->id), 'rawId' => WebAuthn::b64($this->id), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => WebAuthn::b64($clientData),
                'authenticatorData' => WebAuthn::b64($authData),
                'signature' => WebAuthn::b64($sig),
                'userHandle' => $userHandle === null ? null : WebAuthn::b64($userHandle),
            ],
        ];
    }

    /** Minimal CBOR encoder: ints, text, byte strings (CborBytes or COSE coordinates) and maps. */
    public static function cbor(mixed $v): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) {
                return chr($major << 5 | $len);
            }
            if ($len < 256) {
                return chr($major << 5 | 24).chr($len);
            }

            return $len < 65536 ? chr($major << 5 | 25).pack('n', $len) : chr($major << 5 | 26).pack('N', $len);
        };
        if ($v instanceof CborBytes) {
            return $head(2, strlen($v->bytes)).$v->bytes;
        }
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_string($v)) {
            // COSE coordinates and moduli are binary; map keys like "fmt" are text.
            return preg_match('//u', $v) && ctype_print($v) ? $head(3, strlen($v)).$v : $head(2, strlen($v)).$v;
        }
        if (is_array($v)) {
            $out = $head(5, count($v));
            foreach ($v as $k => $item) {
                $out .= self::cbor($k).self::cbor($item);
            }

            return $out;
        }
        throw new \InvalidArgumentException('Unsupported CBOR value');
    }
}

final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}
