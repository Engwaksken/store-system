<?php

namespace App\Support\WebAuthn;

/** Minimal CBOR (RFC 8949) decoder: enough for WebAuthn attestation objects and COSE keys. */
final class Cbor
{
    /** Decodes one item starting at $offset and advances $offset past it. */
    public static function decode(string $data, int &$offset = 0, int $depth = 0): mixed
    {
        if ($depth > 16) {
            throw new WebAuthnException('CBOR nested too deeply.');
        }
        $initial = ord(self::take($data, $offset, 1));
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                25 => self::half(self::take($data, $offset, 2)),
                26 => unpack('G', self::take($data, $offset, 4))[1],
                27 => unpack('E', self::take($data, $offset, 8))[1],
                default => throw new WebAuthnException('Unsupported CBOR simple value.'),
            };
        }

        $len = self::length($data, $offset, $info);

        switch ($major) {
            case 0:
                return $len;
            case 1:
                return -1 - $len;
            case 2:
            case 3:
                return self::take($data, $offset, $len);
            case 4:
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $out[] = self::decode($data, $offset, $depth + 1);
                }

                return $out;
            case 5:
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::decode($data, $offset, $depth + 1);
                    if (! is_int($k) && ! is_string($k)) {
                        throw new WebAuthnException('Unsupported CBOR map key.');
                    }
                    $out[$k] = self::decode($data, $offset, $depth + 1);
                }

                return $out;
            case 6: // tag: return the tagged value
                return self::decode($data, $offset, $depth + 1);
        }

        throw new WebAuthnException('Invalid CBOR.');
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        $len = match ($info) {
            24 => ord(self::take($data, $offset, 1)),
            25 => unpack('n', self::take($data, $offset, 2))[1],
            26 => unpack('N', self::take($data, $offset, 4))[1],
            27 => unpack('J', self::take($data, $offset, 8))[1],
            default => throw new WebAuthnException('Indefinite-length CBOR is not supported.'),
        };
        if ($len < 0 || $len > 1 << 20) {
            throw new WebAuthnException('CBOR length out of range.');
        }

        return $len;
    }

    private static function take(string $data, int &$offset, int $n): string
    {
        if ($n < 0 || $offset + $n > strlen($data)) {
            throw new WebAuthnException('CBOR data is truncated.');
        }
        $out = substr($data, $offset, $n);
        $offset += $n;

        return $out;
    }

    private static function half(string $b): float
    {
        $h = unpack('n', $b)[1];
        $exp = ($h >> 10) & 0x1F;
        $mant = $h & 0x3FF;
        $val = $exp === 0 ? $mant * 2 ** -24 : ($exp !== 31 ? ($mant + 1024) * 2 ** ($exp - 25) : ($mant === 0 ? INF : NAN));

        return $h & 0x8000 ? -$val : $val;
    }
}
