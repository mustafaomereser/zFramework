<?php

namespace zFramework\Core\Facades\PushNotification;

use zFramework\Core\Facades\Str;

/**
 * VAPID (RFC 8292): who is sending.
 *
 * Every request carries a short-lived JWT signed with the application's own
 * P-256 key, and the browser only accepts messages signed by the key it
 * subscribed with - which keeps applications sharing one installation out of
 * each other's subscribers.
 *
 * ES256 is the only algorithm push services accept. openssl signs and derives;
 * the rest is ASN.1 paperwork between openssl's formats and the raw byte
 * strings the browser APIs use.
 */
class VAPID
{
    /**
     * SubjectPublicKeyInfo header for an uncompressed P-256 point. Fixed widths
     * throughout, so it is the same 26 bytes every time.
     */
    private const SPKI_HEADER = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /**
     * How long a signed token stays valid. RFC 8292 caps it at 24h; 12h leaves
     * room for a drifting server clock.
     */
    public const TOKEN_TTL = 43200;

    /**
     * Generate an application's key pair, base64url. The public half goes to
     * the browser as applicationServerKey; replacing it invalidates every
     * subscription taken with the old one.
     *
     * @return array{public: string, private: string}
     * @throws \RuntimeException
     */
    public static function generate(): array
    {
        $arguments = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        # A Windows PHP with no OPENSSL_CONF set has no configuration file to
        # read and fails over a key length it never needed. openssl.cnf next to
        # this class covers that and changes nothing about the key.
        $key = @openssl_pkey_new($arguments) ?: openssl_pkey_new($arguments + ['config' => __DIR__ . '/openssl.cnf']);

        if (!$key) throw new \RuntimeException('VAPID: openssl could not generate a P-256 key pair. ' . openssl_error_string());

        $details = openssl_pkey_get_details($key)['ec'] ?? null;
        if (!$details) throw new \RuntimeException('VAPID: openssl returned a key without EC details.');

        return [
            'public'  => Str::base64UrlEncode(self::point($details['x'], $details['y'])),
            'private' => Str::base64UrlEncode(str_pad($details['d'], 32, "\x00", STR_PAD_LEFT)),
        ];
    }

    /**
     * The `Authorization` header for one request. Signed per request: a
     * signature is ~0.2ms, and caching it would hold a credential in a static
     * for hours.
     *
     * @param string $endpoint   Only its origin is signed.
     * @param string $subject    mailto: or https: url a push service can reach you at.
     * @param string $publicKey  base64url, 65-byte point.
     * @param string $privateKey base64url, 32-byte scalar.
     * @param int    $ttl
     * @return string
     */
    public static function authorization(string $endpoint, string $subject, string $publicKey, string $privateKey, int $ttl = self::TOKEN_TTL): string
    {
        $jwt = self::token([
            'aud' => self::audience($endpoint),
            'exp' => time() + $ttl,
            'sub' => $subject,
        ], $publicKey, $privateKey);

        return "vapid t=$jwt, k=$publicKey";
    }

    /**
     * Sign a JWT with ES256.
     *
     * @param array  $claims
     * @param string $publicKey
     * @param string $privateKey
     * @return string
     * @throws \RuntimeException
     */
    public static function token(array $claims, string $publicKey, string $privateKey): string
    {
        $input = Str::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . Str::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private(self::privateKeyPem($publicKey, $privateKey));
        if (!$key) throw new \RuntimeException('VAPID: the configured private key could not be read. ' . openssl_error_string());

        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) throw new \RuntimeException('VAPID: signing failed. ' . openssl_error_string());

        return "$input." . Str::base64UrlEncode(self::derToJose($der));
    }

    /**
     * The `aud` claim: scheme and host only. The path is the subscription
     * secret, and push services reject a token carrying it anyway.
     *
     * @param string $endpoint
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) throw new \InvalidArgumentException("VAPID: `$endpoint` is not a usable endpoint url.");

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Wrap a raw 65-byte public point as a PEM openssl will read.
     *
     * @param string $publicKey base64url or raw bytes.
     * @return string
     */
    public static function publicKeyPem(string $publicKey): string
    {
        $point = self::bytes($publicKey);
        if (strlen($point) !== 65 || $point[0] !== "\x04") throw new \InvalidArgumentException('VAPID: expected a 65-byte uncompressed P-256 point.');

        return self::pem('PUBLIC KEY', self::SPKI_HEADER . $point);
    }

    /**
     * Wrap a raw 32-byte scalar as a SEC1 EC private key PEM. The public point
     * is passed in rather than derived - PHP has no scalar multiplication.
     *
     * @param string $publicKey  base64url or raw bytes.
     * @param string $privateKey base64url or raw bytes.
     * @return string
     */
    public static function privateKeyPem(string $publicKey, string $privateKey): string
    {
        $point  = self::bytes($publicKey);
        $scalar = str_pad(self::bytes($privateKey), 32, "\x00", STR_PAD_LEFT);

        if (strlen($point) !== 65 || $point[0] !== "\x04") throw new \InvalidArgumentException('VAPID: expected a 65-byte uncompressed P-256 point.');
        if (strlen($scalar) !== 32) throw new \InvalidArgumentException('VAPID: expected a 32-byte private scalar.');

        # ECPrivateKey ::= SEQUENCE { version, privateKey, [0] prime256v1,
        # [1] publicKey }. Fixed widths, so the lengths are constants too.
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $scalar
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $point;

        return self::pem('EC PRIVATE KEY', $der);
    }

    /**
     * Uncompressed point from x/y, left-padded to the curve size: openssl
     * strips leading zeroes, and a short point is rejected everywhere.
     *
     * @param string $x
     * @param string $y
     * @return string
     */
    public static function point(string $x, string $y): string
    {
        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Accept a key as base64url or as raw bytes - keys arrive from config, the
     * database and the browser, and tracking which is which is not worth it.
     *
     * @param string $key
     * @return string
     */
    public static function bytes(string $key): string
    {
        if (strlen($key) === 65 && $key[0] === "\x04") return $key;
        if (strlen($key) === 32 && !preg_match('/^[A-Za-z0-9\-_]+$/', $key)) return $key;

        return Str::base64UrlDecode($key);
    }

    /**
     * ECDSA signature: openssl's DER SEQUENCE { r, s } to JOSE's r || s. DER
     * stores integers minimally and sign-extended, so both halves are trimmed
     * and re-padded to 32 bytes.
     *
     * @param string $der
     * @return string
     * @throws \RuntimeException
     */
    private static function derToJose(string $der): string
    {
        $offset = 2;
        # Long-form length on the outer SEQUENCE: never happens for P-256, but a
        # misparsed signature is worse than a branch that costs nothing.
        if ((ord($der[1]) & 0x80) !== 0) $offset += ord($der[1]) & 0x7f;

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset] ?? null) !== "\x02") throw new \RuntimeException('VAPID: unexpected signature encoding from openssl.');

            $length  = ord($der[$offset + 1]);
            $value   = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            $parts[] = str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /**
     * @param string $label
     * @param string $der
     * @return string
     */
    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }
}
