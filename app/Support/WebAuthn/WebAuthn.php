<?php

namespace App\Support\WebAuthn;

/**
 * Server side of WebAuthn (passkeys), kept deliberately small:
 * - algorithms ES256 (-7) and RS256 (-257), which every platform authenticator supports;
 * - attestation "none" (we trust the user's own device; no vendor attestation checks);
 * - user verification is required (fingerprint, face or device PIN), not just user presence.
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;

    public const ALG_RS256 = -257;

    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    /** @param  string[]  $origins  allowed origins, e.g. https://store.example.com */
    public function __construct(public readonly string $rpId, public readonly array $origins) {}

    public static function fromConfig(): self
    {
        $appUrl = (string) config('app.url');
        $rpId = config('auth.passkeys.rp_id') ?: (parse_url($appUrl, PHP_URL_HOST) ?: 'localhost');
        $origins = array_filter(array_map('trim', explode(',', (string) (config('auth.passkeys.origins') ?: self::originOf($appUrl)))));

        return new self($rpId, array_values(array_map(fn ($o) => rtrim($o, '/'), $origins)));
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url) ?: [];

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? 'localhost').(isset($p['port']) ? ':'.$p['port'] : '');
    }

    public static function challenge(): string
    {
        return self::b64(random_bytes(32));
    }

    /**
     * Verifies a registration (navigator.credentials.create) response.
     *
     * @return array{credential_id: string, public_key: string, sign_count: int}
     */
    public function verifyRegistration(array $credential, string $expectedChallenge): array
    {
        $this->clientData($credential, 'webauthn.create', $expectedChallenge);

        $offset = 0;
        $att = Cbor::decode(self::unb64($credential['response']['attestationObject'] ?? null), $offset);
        if (! is_array($att) || ! is_string($att['authData'] ?? null)) {
            throw new WebAuthnException('Malformed attestation object.');
        }
        $auth = $this->authData($att['authData']);
        if (! ($auth['flags'] & self::FLAG_AT) || $auth['attested'] === null) {
            throw new WebAuthnException('No credential in the response.');
        }

        $credentialId = $auth['attested']['credential_id'];
        if (! hash_equals(self::b64($credentialId), self::b64(self::unb64($credential['rawId'] ?? $credential['id'] ?? null)))) {
            throw new WebAuthnException('Credential id mismatch.');
        }

        return [
            'credential_id' => self::b64($credentialId),
            'public_key' => self::coseToPem($auth['attested']['cose']),
            'sign_count' => $auth['sign_count'],
        ];
    }

    /**
     * Verifies a sign-in (navigator.credentials.get) response against the stored key.
     * Returns the new signature counter.
     */
    public function verifyAssertion(array $credential, string $expectedChallenge, string $publicKeyPem, int $storedSignCount): int
    {
        $clientJson = self::unb64($credential['response']['clientDataJSON'] ?? null);
        $this->clientData($credential, 'webauthn.get', $expectedChallenge);
        $rawAuth = self::unb64($credential['response']['authenticatorData'] ?? null);
        $auth = $this->authData($rawAuth);
        $signature = self::unb64($credential['response']['signature'] ?? null);

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false || openssl_verify($rawAuth.hash('sha256', $clientJson, true), $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new WebAuthnException('Signature is not valid.');
        }

        // A counter that does not move forward means the authenticator may have been cloned.
        // Passkeys that sync between devices always report 0, which is allowed.
        if (($auth['sign_count'] !== 0 || $storedSignCount !== 0) && $auth['sign_count'] <= $storedSignCount) {
            throw new WebAuthnException('Signature counter did not increase.');
        }

        return $auth['sign_count'];
    }

    private function clientData(array $credential, string $type, string $expectedChallenge): array
    {
        if (($credential['type'] ?? 'public-key') !== 'public-key') {
            throw new WebAuthnException('Not a public-key credential.');
        }
        $data = json_decode(self::unb64($credential['response']['clientDataJSON'] ?? null), true);
        if (! is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new WebAuthnException('Wrong ceremony type.');
        }
        if (! is_string($data['challenge'] ?? null) || ! hash_equals($expectedChallenge, $data['challenge'])) {
            throw new WebAuthnException('Challenge does not match.');
        }
        if (! in_array(rtrim((string) ($data['origin'] ?? ''), '/'), $this->origins, true)) {
            throw new WebAuthnException('Origin '.($data['origin'] ?? '?').' is not allowed.');
        }

        return $data;
    }

    /** @return array{flags: int, sign_count: int, attested: ?array{credential_id: string, cose: array}} */
    private function authData(string $raw): array
    {
        if (strlen($raw) < 37) {
            throw new WebAuthnException('Authenticator data is too short.');
        }
        if (! hash_equals(hash('sha256', $this->rpId, true), substr($raw, 0, 32))) {
            throw new WebAuthnException('Relying party id does not match.');
        }
        $flags = ord($raw[32]);
        if (! ($flags & self::FLAG_UP)) {
            throw new WebAuthnException('User presence was not confirmed.');
        }
        if (! ($flags & self::FLAG_UV)) {
            throw new WebAuthnException('User verification (fingerprint, face or PIN) was not performed.');
        }
        $signCount = unpack('N', substr($raw, 33, 4))[1];

        $attested = null;
        if ($flags & self::FLAG_AT) {
            if (strlen($raw) < 55) {
                throw new WebAuthnException('Attested credential data is truncated.');
            }
            $idLen = unpack('n', substr($raw, 53, 2))[1];
            $credentialId = substr($raw, 55, $idLen);
            if ($idLen === 0 || strlen($credentialId) !== $idLen) {
                throw new WebAuthnException('Credential id is truncated.');
            }
            $offset = 55 + $idLen;
            $cose = Cbor::decode($raw, $offset);
            if (! is_array($cose)) {
                throw new WebAuthnException('Malformed public key.');
            }
            $attested = ['credential_id' => $credentialId, 'cose' => $cose];
        }

        return ['flags' => $flags, 'sign_count' => $signCount, 'attested' => $attested];
    }

    /** COSE_Key (RFC 9053) to a PEM SubjectPublicKeyInfo usable by openssl_verify. */
    public static function coseToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === self::ALG_ES256) {
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if (($cose[-1] ?? null) !== 1 || ! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new WebAuthnException('Unsupported EC key (P-256 only).');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;
        } elseif ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if (! is_string($n) || ! is_string($e) || strlen($n) < 256) {
                throw new WebAuthnException('Unsupported RSA key (2048 bits or more).');
            }
            $rsaKey = self::der(0x30, self::derInt($n).self::derInt($e));
            $algId = self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101'))."\x05\x00");
            $der = self::der(0x30, $algId.self::der(0x03, "\x00".$rsaKey));
        } else {
            throw new WebAuthnException('Unsupported key algorithm.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new WebAuthnException('Public key could not be read.');
        }

        return $pem;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            return chr($tag).chr($len).$content;
        }
        $lenBytes = ltrim(pack('N', $len), "\x00");

        return chr($tag).chr(0x80 | strlen($lenBytes)).$lenBytes.$content;
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(mixed $s): string
    {
        if (! is_string($s) || $s === '' || strlen($s) > 1 << 16) {
            throw new WebAuthnException('Missing or invalid field.');
        }
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        if ($bin === false) {
            throw new WebAuthnException('Invalid base64url.');
        }

        return $bin;
    }
}
