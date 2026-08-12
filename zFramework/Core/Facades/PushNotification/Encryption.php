<?php

namespace zFramework\Core\Facades\PushNotification;

use zFramework\Core\Facades\Str;

/**
 * Payload encryption for web push (RFC 8291, over RFC 8188's aes128gcm).
 *
 * The push service is a relay nobody controls, holding the message until the
 * device connects, so the payload is encrypted for the subscription itself and
 * the relay carries bytes it cannot read.
 *
 * The sender key pair is new for every message - not the VAPID identity key; it
 * agrees one message's secret, travels in the body and is thrown away.
 *
 * Checked against RFC 8291 §5: `php terminal push-notification test`.
 */
class Encryption
{
    /**
     * Record size written into the header. One record per message, so this is
     * the ceiling a receiver is told to expect, not a chunking decision.
     */
    public const RECORD_SIZE = 4096;

    /**
     * Largest plaintext that fits: the record, minus the GCM tag, minus the
     * padding delimiter.
     */
    public const MAX_PAYLOAD = self::RECORD_SIZE - 16 - 1;

    /**
     * Encrypt one payload for one subscription.
     *
     * @param string      $payload Bytes to deliver; the browser hands them to the
     *                             service worker untouched.
     * @param string      $p256dh  Subscription public key, base64url.
     * @param string      $auth    Subscription auth secret, base64url (16 bytes).
     * @param array|null  $sender  ['public' => ..., 'private' => ...]. Generated
     *                             when omitted; passed in only to reproduce a
     *                             known test vector.
     * @param string|null $salt    16 bytes. Same reasoning as $sender.
     * @return string Body to POST, `Content-Encoding: aes128gcm`.
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?array $sender = null, ?string $salt = null): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) throw new \InvalidArgumentException('Push: payload is ' . strlen($payload) . ' bytes, the limit is ' . self::MAX_PAYLOAD . '. Send an identifier and let the service worker fetch the rest.');

        $receiverPublic = VAPID::bytes($p256dh);
        $authSecret     = Str::base64UrlDecode($auth);

        if (strlen($receiverPublic) !== 65) throw new \InvalidArgumentException('Push: subscription p256dh is not a 65-byte P-256 point.');
        if (strlen($authSecret) !== 16) throw new \InvalidArgumentException('Push: subscription auth secret is not 16 bytes.');

        $sender ??= VAPID::generate();
        $salt   ??= random_bytes(16);

        $senderPublic = VAPID::bytes($sender['public']);
        $senderKey    = openssl_pkey_get_private(VAPID::privateKeyPem($sender['public'], $sender['private']));
        if (!$senderKey) throw new \RuntimeException('Push: could not load the message key pair. ' . openssl_error_string());

        $shared = openssl_pkey_derive(VAPID::publicKeyPem($receiverPublic), $senderKey, 32);
        if (!$shared) throw new \RuntimeException('Push: ECDH with the subscription key failed. ' . openssl_error_string());

        # RFC 8291 §3.3. The auth secret salts the first HKDF pass, so a shared
        # secret alone cannot derive the key - only whoever holds the
        # subscription can.
        $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
        $ikm    = hash_hmac('sha256', "WebPush: info\x00" . $receiverPublic . $senderPublic . "\x01", $prkKey, true);
        $prk    = hash_hmac('sha256', $ikm, $salt, true);

        $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        # 0x02 marks the last record. Padding to hide the length would go before
        # it; nothing here pads.
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) throw new \RuntimeException('Push: AES-128-GCM encryption failed. ' . openssl_error_string());

        # RFC 8188 §2.1 header: salt, record size, key length, key. The receiver
        # needs the sender's public key for the same ECDH, so it travels in the
        # clear.
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($senderPublic)) . $senderPublic . $ciphertext . $tag;
    }
}
