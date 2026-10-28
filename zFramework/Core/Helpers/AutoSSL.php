<?php

namespace zFramework\Core\Helpers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class AutoSSL
{
    # Let's Encrypt.
    public const LE_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    public const LE_PROD    = 'https://acme-v02.api.letsencrypt.org/directory';

    # Google Trust Services (https://pki.goog). Both require External Account
    # Binding: an account is registered once with an EAB key from Google Cloud -
    # see createAccount(). Domains with a CAA record must allow `pki.goog`.
    public const GTS_STAGING = 'https://dv.acme-v02.test-api.pki.goog/directory';
    public const GTS_PROD    = 'https://dv.acme-v02.api.pki.goog/directory';

    private string $sslPath;
    private string $webChallengePath;

    /**
     * One directory per ACME account under <sslPath>/accounts/<id>/, holding
     * account.key and account.kid. The id is a number - date and time the account
     * was created plus three random digits - so a listing sorts by age and two
     * created in the same second do not collide.
     */
    private string $accountsPath;
    private string $account;
    private string $accountKeyPath;
    private string $directoryUrl;
    private $accountKeyRes;
    private array $dir           = [];
    private array $openSSLConfig = ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    private ?string $kid = null;

    /**
     * The next nonce, taken from the last response. Null fetches a fresh one.
     */
    private ?string $nonce = null;

    /**
     * External Account Binding for the next registration, if the CA wants one.
     * Held for this instance only - never written to disk.
     */
    private ?array $eab = null;

    /**
     * @param string      $directoryUrl  AutoSSL::LE_PROD / LE_STAGING, GTS_PROD / GTS_STAGING, or any ACME directory.
     * @param string|null $openSSLConfig Path to an openssl.cnf, for Windows.
     * @param string|null $account       Which account to work as - an id from accounts().
     *                                   Null takes the oldest one there is, or registers
     *                                   a new one when there is none.
     * @param array|null  $eab           ['kid' => ..., 'hmac' => ...] when this CA requires External
     *                                   Account Binding (GTS) and an account has to be registered.
     *                                   Used once; the registered account then works without it.
     */
    public function __construct(string $directoryUrl = self::LE_STAGING,?string $openSSLConfig = null, ?string $account = null, ?array $eab = null)
    {
        $this->eab = $eab;
        global $storage_path;

        # Windows PHP has no openssl.cnf unless OPENSSL_CONF says where one is, and
        # without one openssl_pkey_new() fails - every account and domain key with
        # it. The minimal file the push-notification facade already ships is used
        # when nothing was given.
        $fallback = dirname(__DIR__) . '/Facades/PushNotification/openssl.cnf';
        if ($openSSLConfig === null && PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF') && is_file($fallback)) $openSSLConfig = $fallback;
        if (!is_null($openSSLConfig)) $this->openSSLConfig['config'] = $openSSLConfig;

        $this->sslPath          = $storage_path . "/AutoSSL";
        $this->accountsPath     = $this->sslPath . '/accounts';
        $this->directoryUrl     = $directoryUrl;
        $this->webChallengePath = public_dir('/.well-known/acme-challenge');

        if (!is_dir($this->accountsPath)) mkdir($this->accountsPath, 0755, true);
        if (!is_dir($this->webChallengePath)) mkdir($this->webChallengePath, 0755, true);

        $this->adoptLegacyAccount();
        $this->loadDirectory();

        # The oldest account registered with THIS CA - not the oldest there is. A
        # staging account picked up by a production instance signed every request
        # with a kid Let's Encrypt's production directory has never heard of.
        if ($account !== null) {
            $this->useAccount($account);
            $state = $this->verifyAccount();
            if (!$state['valid']) throw new \RuntimeException("AutoSSL: account `$account` is no longer usable at " . parse_url($this->directoryUrl, PHP_URL_HOST) . ": {$state['reason']} - createAccount() registers a new one.");
            return;
        }

        # Registered accounts first, oldest first; then keys that were never
        # registered. Each is asked whether the CA still knows it: an account the
        # CA has deactivated or forgotten used to fail every request from then on
        # - "the kid went stale" - until someone deleted storage/AutoSSL, losing
        # the certificates with it. Such an account is now retired (a marker file,
        # nothing deleted) and the next one tried, or a new one registered.
        $usable = array_filter($this->accounts(), fn($a) => $a['usable']);
        uasort($usable, fn($a, $b) => [!$a['registered'], $a['id']] <=> [!$b['registered'], $b['id']]);

        foreach (array_keys($usable) as $id) {
            try {
                $this->useAccount($id);
            } catch (\InvalidArgumentException) {
                continue; # an unregistered key this CA will not take without EAB
            }

            $state = $this->verifyAccount();
            if ($state['valid']) return;

            $this->retireAccount($id, $state['reason']);
        }

        $this->createAccount();
    }

    /**
     * Ask the CA whether the current account is still valid.
     *
     * An empty update to the account url (RFC 8555 §7.3.2) answers with the
     * account object. Only an answer that says no retires it - a network error
     * or a 5xx says nothing about the account and counts as valid.
     *
     * @return array{valid: bool, reason: string}
     */
    public function verifyAccount(): array
    {
        if (!$this->kid) return ['valid' => true, 'reason' => 'not registered yet'];

        try {
            $res = $this->postAsJWS($this->kid, new \stdClass);
        } catch (\Throwable $e) {
            return ['valid' => true, 'reason' => 'not verified: ' . $e->getMessage()];
        }

        $status = (string) (json_decode($res['body'], true)['status'] ?? '');

        if ($res['status'] === 200 && $status === 'valid') return ['valid' => true, 'reason' => 'valid'];
        if ($res['status'] === 200) return ['valid' => false, 'reason' => "account is $status"];
        if (in_array($res['status'], [400, 401, 403], true)) return ['valid' => false, 'reason' => self::problem($res)];

        return ['valid' => true, 'reason' => 'not verified: ' . self::problem($res)];
    }

    /**
     * Stop picking an account the CA no longer accepts. Its files stay; the
     * reason is written next to them.
     *
     * @param string $id
     * @param string $reason
     * @return void
     */
    private function retireAccount(string $id, string $reason): void
    {
        @file_put_contents($this->accountsPath . '/' . basename($id) . '/account.retired', date('Y-m-d H:i:s') . " $reason\n");
        if (isset($this->account) && $this->account === basename($id)) $this->kid = null;
    }

    /**
     * The id of the account this instance signs with.
     *
     * @return string
     */
    public function account(): string
    {
        return $this->account;
    }

    /**
     * Every account on disk, oldest first.
     *
     * @return array<string, array{id: string, kid: ?string, registered: bool, ca: ?string, retired: string|false, usable: bool, created: string, current: bool}>
     */
    public function accounts(): array
    {
        $accounts = [];

        foreach (glob($this->accountsPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_file("$dir/account.key")) continue;

            $id  = basename($dir);
            $kid = is_file("$dir/account.kid") ? trim((string) file_get_contents("$dir/account.kid")) : null;

            # The kid is a url on the CA that issued it; an account belongs to the
            # directory whose host it names. Unregistered keys belong to nobody yet.
            $ca = $kid ? (string) parse_url($kid, PHP_URL_HOST) : null;

            $retired = is_file("$dir/account.retired");

            $accounts[$id] = [
                'id'         => $id,
                'kid'        => $kid ?: null,
                'registered' => (bool) $kid,
                'ca'         => $ca,
                'retired'    => $retired ? trim((string) file_get_contents("$dir/account.retired")) : false,
                'usable'     => !$retired && ($ca === null || $ca === parse_url($this->directoryUrl, PHP_URL_HOST)),
                'created'    => date('Y-m-d H:i:s', (int) filemtime("$dir/account.key")),
                'current'    => isset($this->account) && $this->account === $id,
            ];
        }

        ksort($accounts, SORT_STRING);

        return $accounts;
    }

    /**
     * Register a new account and switch this instance to it.
     *
     * A CA that requires External Account Binding (GTS) refuses a registration
     * without one; get the key pair from Google Cloud
     * (`gcloud publicca external-account-keys create`) and pass it here, or to
     * the constructor. It is used for this one registration and not stored.
     *
     * @param array|null $eab ['kid' => ..., 'hmac' => ...]; null uses the constructor's.
     * @return string The new account's id.
     */
    public function createAccount(?array $eab = null): string
    {
        if ($eab !== null) $this->eab = $eab;

        # Said before anything is written: otherwise a key directory was left behind
        # for every attempt, and picked up next time as an unregistered account.
        if ($this->requiresEab() && !$this->eab)
            throw new \InvalidArgumentException('AutoSSL: ' . parse_url($this->directoryUrl, PHP_URL_HOST) . ' requires External Account Binding - pass ["kid" => ..., "hmac" => ...] to createAccount() or the constructor (for GTS: gcloud publicca external-account-keys create).');

        do $id = date('YmdHis') . random_int(100, 999);
        while (is_dir($this->accountsPath . "/$id"));

        mkdir($this->accountsPath . "/$id", 0755, true);

        $this->account        = $id;
        $this->accountKeyPath = $this->accountsPath . "/$id/account.key";
        $this->kid            = null;

        $this->generateAccountKey();
        $this->loadAccountKey();
        $this->ensureAccount();

        return $id;
    }

    /**
     * Switch this instance to an existing account.
     *
     * @param string $id
     * @return self
     */
    public function useAccount(string $id): self
    {
        $dir = $this->accountsPath . '/' . basename($id);
        if (!is_file("$dir/account.key")) throw new \InvalidArgumentException("AutoSSL: no account `$id` - see accounts().");

        $this->account        = basename($id);
        $this->accountKeyPath = "$dir/account.key";
        $this->kid            = null;

        $this->loadAccountKey();

        # A key that was generated but never registered - the registration call
        # failed, or the process died between the two - is registered now.
        if (is_file("$dir/account.kid")) $this->kid = trim((string) file_get_contents("$dir/account.kid")) ?: null;

        if ($this->kid && parse_url($this->kid, PHP_URL_HOST) !== parse_url($this->directoryUrl, PHP_URL_HOST))
            throw new \InvalidArgumentException("AutoSSL: account `$id` is registered with " . parse_url($this->kid, PHP_URL_HOST) . ", this instance talks to " . parse_url($this->directoryUrl, PHP_URL_HOST) . " - see accounts(), or createAccount().");

        if (!$this->kid) $this->ensureAccount();

        return $this;
    }

    /**
     * Move the single account older versions kept at the root into accounts/<id>/.
     *
     * Only the files move; the key and the kid are what identify the account to
     * the CA, so it is the same account afterwards and every certificate it issued
     * still renews under it.
     *
     * @return void
     */
    private function adoptLegacyAccount(): void
    {
        if (!is_file($this->sslPath . '/account.key')) return;

        $id  = date('YmdHis', (int) filemtime($this->sslPath . '/account.key')) . '000';
        $dir = $this->accountsPath . "/$id";

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        rename($this->sslPath . '/account.key', "$dir/account.key");
        if (is_file($this->sslPath . '/account.kid')) rename($this->sslPath . '/account.kid', "$dir/account.kid");
    }

    /**
     * One HTTP request. Header names come back lower-cased - HTTP/2 sends them
     * that way and HTTP/1.1 servers do not, and the callers asked for
     * `Location` and `location` both to cover it.
     *
     * Timeouts are set: without them a CA that stopped answering held a cron
     * run - and renewAll() with it - for as long as the connection stayed open.
     * HEAD is NOBODY, not a custom verb: curl told to send HEAD as a custom
     * request still waits for the body the headers announce.
     */
    private function httpRequest(string $url, string $method = 'GET', $body = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'zFramework-AutoSSL',
        ]);

        if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        elseif ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL error: $err");
        }
        $hsize      = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawHeaders = substr($res, 0, $hsize);
        $body       = substr($res, $hsize);
        curl_close($ch);

        # Only the last header block: a `100 Continue` comes first on some servers.
        $blocks     = array_values(array_filter(preg_split("/\r?\n\r?\n/", trim($rawHeaders))));
        $rawHeaders = end($blocks) ?: '';

        $hdrs = [];
        foreach (preg_split("/\r?\n/", $rawHeaders) as $line) if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }

        # Every ACME response carries the next nonce; keeping it saves a round trip
        # per request and is what the protocol expects (RFC 8555 §7.2).
        if (isset($hdrs['replay-nonce'])) $this->nonce = $hdrs['replay-nonce'];

        return ['status' => $status, 'headers' => $hdrs, 'body' => $body];
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /* -------------------- Account key + JWK -------------------- */

    private function generateAccountKey(): void
    {
        $key = openssl_pkey_new($this->openSSLConfig);

        # Checked: unchecked, a failed generation went on to export false and left
        # an account directory behind with no usable key in it.
        if ($key === false || !openssl_pkey_export($key, $pem, null, $this->openSSLConfig)) {
            @rmdir(dirname($this->accountKeyPath));
            throw new \RuntimeException('AutoSSL: account key generation failed: ' . (openssl_error_string() ?: 'unknown') . ' - on Windows pass an openssl.cnf to the constructor.');
        }

        file_put_contents($this->accountKeyPath, $pem);
        @chmod($this->accountKeyPath, 0600);
    }

    private function loadAccountKey(): void
    {
        $pem = file_get_contents($this->accountKeyPath);
        $res = openssl_pkey_get_private($pem);
        if ($res === false) throw new \Exception("Cannot load account key");
        $this->accountKeyRes = $res;
    }

    private function getJWK(): array
    {
        $details = openssl_pkey_get_details($this->accountKeyRes);
        if (!isset($details['rsa'])) {
            if (isset($details['key'])) throw new \Exception("Unexpected key details structure");
            throw new \Exception("Unsupported key type");
        }
        $rsa = $details['rsa'];
        return ['kty' => 'RSA', 'n' => self::base64url($rsa['n']), 'e' => self::base64url($rsa['e'])];
    }

    private function jwkThumbprint(array $jwk): string
    {
        // RFC7638 canonical ordering
        $obj = ['e' => $jwk['e'], 'kty' => $jwk['kty'], 'n' => $jwk['n']];
        $json = json_encode($obj, JSON_UNESCAPED_SLASHES);
        return self::base64url(hash('sha256', $json, true));
    }

    /* -------------------- ACME directory + nonce + JWS -------------------- */

    private function loadDirectory(): void
    {
        $r = $this->httpRequest($this->directoryUrl, 'GET');
        if ($r['status'] !== 200) throw new \Exception("Cannot load ACME directory");
        $this->dir = json_decode($r['body'], true);
    }

    /**
     * A nonce for the next signed request, used once.
     *
     * The one the last response carried if there is one, else a fresh one from
     * newNonce. Taken out as it is used: a nonce sent twice is a badNonce.
     */
    private function getNonce(): string
    {
        if ($this->nonce !== null) {
            $nonce       = $this->nonce;
            $this->nonce = null;
            return $nonce;
        }

        $url = $this->dir['newNonce'] ?? ($this->directoryUrl . '/new-nonce');
        $this->httpRequest($url, 'HEAD');

        $nonce       = $this->nonce;
        $this->nonce = null;
        if ($nonce === null) throw new \Exception("No Replay-Nonce received");
        return $nonce;
    }

    private function signJWS(string $url, $payload, ?string $kid = null): string
    {
        $nonce = $this->getNonce();
        $payloadJson = ($payload === '' || $payload === null) ? '' : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES));
        $payload64 = self::base64url($payloadJson === '' ? '' : $payloadJson);
        if ($kid === null) $protected = ['alg' => 'RS256', 'jwk' => $this->getJWK(), 'nonce' => $nonce, 'url' => $url];
        else $protected = ['alg' => 'RS256', 'kid' => $kid, 'nonce' => $nonce, 'url' => $url];
        $protected64 = self::base64url(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $sigInput = $protected64 . '.' . $payload64;
        $sig = '';
        if (!openssl_sign($sigInput, $sig, $this->accountKeyRes, OPENSSL_ALGO_SHA256)) throw new \Exception("openssl_sign failed: " . openssl_error_string());
        $sig64 = self::base64url($sig);
        return json_encode(['protected' => $protected64, 'payload' => $payload64, 'signature' => $sig64]);
    }

    /**
     * A signed POST, retried where the protocol says to retry.
     *
     * badNonce is part of normal operation - a CA may reject any nonce, and
     * RFC 8555 §6.5 has the client try again with the fresh one the error
     * carries. Not retrying it was the "JWS has an invalid anti-replay nonce"
     * that failed a renewal now and then and passed on the next run. A 5xx or a
     * short Retry-After is tried again once too; everything else is returned.
     */
    private function postAsJWS(string $url, $payload): array
    {
        for ($attempt = 1; ; $attempt++) {
            $res  = $this->httpRequest($url, 'POST', $this->signJWS($url, $payload, $this->kid), ['Content-Type: application/jose+json']);
            $type = (string) (json_decode($res['body'], true)['type'] ?? '');

            if ($res['status'] === 400 && str_ends_with($type, ':badNonce') && $attempt < 4) continue;

            if (($res['status'] >= 500 || $res['status'] === 429) && $attempt < 2) {
                $wait = (int) ($res['headers']['retry-after'] ?? 2);
                if ($wait <= 10) {
                    sleep(max(1, $wait));
                    continue;
                }
            }

            return $res;
        }
    }

    /**
     * POST-as-GET (RFC 8555 §6.3): how orders, authorizations and certificates
     * are read. A plain GET still works on Let's Encrypt but is not the protocol,
     * and a CA that follows it to the letter answers 405.
     */
    private function postAsGet(string $url): array
    {
        return $this->postAsJWS($url, '');
    }

    /**
     * The error an ACME response carries, as one line: type and detail.
     */
    private static function problem(array $res): string
    {
        $data = json_decode($res['body'], true);
        if (!is_array($data)) return 'HTTP ' . $res['status'] . ' ' . substr((string) $res['body'], 0, 200);

        return 'HTTP ' . $res['status'] . ' ' . preg_replace('/^urn:ietf:params:acme:error:/', '', (string) ($data['type'] ?? '')) . ' - ' . ($data['detail'] ?? '');
    }

    /**
     * Whether this directory refuses a registration without External Account Binding.
     */
    public function requiresEab(): bool
    {
        return (bool) ($this->dir['meta']['externalAccountRequired'] ?? false);
    }

    /**
     * The externalAccountBinding member of a newAccount request (RFC 8555 §7.3.4):
     * a JWS over this account's public key, signed with the CA-issued HMAC key.
     *
     * @param array  $eab ['kid' => ..., 'hmac' => base64url]
     * @param string $url The newAccount url.
     * @return array
     */
    private function signEab(array $eab, string $url): array
    {
        if (empty($eab['kid']) || empty($eab['hmac'])) throw new \InvalidArgumentException('AutoSSL: an EAB needs both "kid" and "hmac".');

        $protected = self::base64url(json_encode(['alg' => 'HS256', 'kid' => (string) $eab['kid'], 'url' => $url], JSON_UNESCAPED_SLASHES));
        $payload   = self::base64url(json_encode($this->getJWK(), JSON_UNESCAPED_SLASHES));
        $key       = base64_decode(strtr((string) $eab['hmac'], '-_', '+/'), true);
        if ($key === false || $key === '') throw new \InvalidArgumentException('AutoSSL: the EAB "hmac" is not base64url.');

        return ['protected' => $protected, 'payload' => $payload, 'signature' => self::base64url(hash_hmac('sha256', "$protected.$payload", $key, true))];
    }

    public function ensureAccount(): string
    {
        if ($this->kid) return $this->kid;
        $url     = $this->dir['newAccount'];
        $payload = ['termsOfServiceAgreed' => true];

        if ($this->eab) $payload['externalAccountBinding'] = $this->signEab($this->eab, $url);
        elseif ($this->requiresEab())
            throw new \InvalidArgumentException('AutoSSL: account `' . $this->account . '` is not registered yet and ' . parse_url($this->directoryUrl, PHP_URL_HOST) . ' requires External Account Binding - pass the EAB key to the constructor or createAccount().');

        $resp = $this->postAsJWS($url, $payload);
        if (!in_array($resp['status'], [200, 201])) throw new \Exception("Account creation failed: " . self::problem($resp));

        # One registration per EAB key: it is spent now.
        $this->eab = null;

        $loc = $resp['headers']['location'] ?? null;
        if (!$loc) throw new \Exception("No account Location header");
        $this->kid = $loc;
        file_put_contents(dirname($this->accountKeyPath) . '/account.kid', $loc);
        return $loc;
    }

    /**
     * Forget an account - the current one, or the id given. Local files only; the CA
     * keeps its side, and certificates issued under it stay where they are.
     *
     * @param string|null $id
     * @return void
     */
    public function unlinkAccount(?string $id = null): void
    {
        $id  = basename($id ?? $this->account);
        $dir = $this->accountsPath . "/$id";

        foreach (['account.kid', 'account.key', 'account.retired'] as $file) if (is_file("$dir/$file")) unlink("$dir/$file");
        if (is_dir($dir)) @rmdir($dir);

        if ($id === $this->account) {
            $this->kid = null;
            unset($this->account);
        }
    }

    public function checkSSL(string $domain): array
    {
        $ctx    = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
        $client = @stream_socket_client("ssl://$domain:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) throw new \Exception("Cannot connect to $domain:443 — $errstr ($errno)");
        $cont = stream_context_get_params($client);
        fclose($client);
        if (!isset($cont["options"]["ssl"]["peer_certificate"])) throw new \Exception("No peer certificate captured for $domain");
        $cert = openssl_x509_parse($cont["options"]["ssl"]["peer_certificate"]);
        if (!$cert) throw new \Exception("Cannot parse certificate for $domain");
        return ['domain' => $cert['subject']['CN'], 'givenby' => $cert['issuer']['O'], 'last_date' => date('Y-m-d H:i:s', $cert['validTo_time_t']), 'days_left' => floor(($cert['validTo_time_t'] - time()) / 86400)];
    }

    public function list(): array
    {
        # accounts/ sits beside the domain folders and is not one.
        return array_values(array_filter(glob($this->sslPath . '/*', GLOB_ONLYDIR) ?: [], fn($dir) => basename($dir) !== 'accounts'));
    }

    public function download(string $domain): array
    {
        $folder  = $this->sslPath . "/$domain";
        if (!is_dir($folder)) throw new \Exception("Domain is not exists");

        $zip      = new ZipArchive();
        $temp_zip = tempnam(sys_get_temp_dir(), 'zip');
        # An exception, not exit(): exit ended the whole process - a worker included.
        if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) throw new \RuntimeException("Zip cannot open!");
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            # A half-finished renewal's key is not part of what is installed.
            if (str_ends_with($filePath, '.new')) continue;
            $zip->addFile($filePath, substr($filePath, strlen($folder) + 1));
        }
        $zip->close();

        ob_start();
        readfile($temp_zip);
        $raw      = ob_get_clean();
        $filesize = filesize($temp_zip);

        unlink($temp_zip);
        return ['filename' => "$domain.zip", 'filesize' => $filesize, 'raw' => $raw];
    }

    public function prepareDomain(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') throw new \Exception("Domain empty");
        $dirName = preg_replace('/^\*\./', 'wildcard.', $domain);
        $dir = $this->sslPath . '/' . $dirName;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        return compact('domain', 'dir');
    }

    public function newOrder(array $domains = []): array
    {
        $res = $this->postAsJWS($this->dir['newOrder'], ['identifiers' => array_map(fn($domain) => ['type' => 'dns', 'value' => $domain], $domains)]);

        if (!in_array($res['status'], [201, 200])) throw new \Exception("newOrder failed: " . self::problem($res));
        $order    = json_decode($res['body'], true);
        $location = $res['headers']['location'] ?? null;
        if (!$location) throw new \Exception("No order location");

        $authUrls = $order['authorizations'] ?? [];
        if (count($authUrls) === 0) throw new \Exception("No authorizations in order");

        // GET each authorization to collect its challenges (one authorization per identifier).
        $authorizations = [];
        foreach ($authUrls as $authUrl) {
            $authResp = $this->postAsGet($authUrl);
            if ($authResp['status'] !== 200) throw new \Exception("Cannot load authorization: " . self::problem($authResp));
            $adata = json_decode($authResp['body'], true);
            $authorizations[] = [
                'url'        => $authUrl,
                'identifier' => $adata['identifier']['value'] ?? null,
                'wildcard'   => $adata['wildcard'] ?? false,
                'status'     => $adata['status'] ?? null,
                'challenges' => $adata['challenges'] ?? [],
            ];
        }

        // prepare per-domain storage folders.
        $preparedDomains = [];
        foreach ($domains as $domain) {
            $prepare = $this->prepareDomain($domain);
            $preparedDomains[$prepare['domain']] = $prepare['dir'];
        }

        $finalize = $order['finalize'] ?? null;

        return compact('order', 'location', 'finalize', 'authorizations') + ['domains' => $preparedDomains];
    }

    public function challenge(array $authorizations, string $type = "http-01"): array
    {
        $thumbprint = $this->jwkThumbprint($this->getJWK());

        $list = [];
        foreach ($authorizations as $auth) {
            # Already proven: a CA reuses a valid authorization for a while (Let's
            # Encrypt, up to 30 days), so a renewal often has nothing to answer.
            # Publishing and notifying it anyway was a pointless request - and for
            # dns-01, a TXT record asked of someone for nothing.
            if (($auth['status'] ?? null) === 'valid') continue;

            $selected = null;
            foreach ($auth['challenges'] as $challenge) if ($challenge['type'] === $type) {
                $selected = $challenge;
                break;
            }
            if (!$selected) throw new \Exception("No $type challenge available for " . ($auth['identifier'] ?? '?'));

            $keyAuth = $selected['token'] . '.' . $thumbprint;
            $domain  = ($auth['wildcard'] ? '*.' : '') . $auth['identifier'];

            $entry = [
                'type'    => $type,
                'authUrl' => $auth['url'],
                'domain'  => $domain,
                'url'     => $selected['url'],
                'token'   => $selected['token'],
            ];

            if ($type === 'dns-01') {
                // TXT record name strips the wildcard prefix; value is the SHA-256 digest of the key authorization.
                $entry['record'] = '_acme-challenge.' . preg_replace('/^\*\./', '', $domain);
                $entry['value']  = self::base64url(hash('sha256', $keyAuth, true));
            } else {
                $entry['content']  = $keyAuth;
                $entry['filePath'] = $this->webChallengePath . '/' . $selected['token'];
            }

            $list[] = $entry;
        }

        return $list;
    }

    public function publishChallenge(array $challenge): void
    {
        if (($challenge['type'] ?? null) !== 'http-01') throw new \Exception("publishChallenge only supports http-01; dns-01 TXT records must be published manually");
        if (file_put_contents($challenge['filePath'], $challenge['content']) === false) throw new \Exception("Cannot write challenge file");
        chmod($challenge['filePath'], 0644);
    }

    /**
     * Why an authorization failed, in the CA's words: the challenge's error -
     * "Invalid response from http://.../.well-known/acme-challenge/...: 404" and
     * the like - rather than the whole authorization object.
     *
     * @param array $authorization
     * @return string
     */
    private static function authorizationError(array $authorization): string
    {
        foreach ($authorization['challenges'] ?? [] as $challenge)
            if (!empty($challenge['error'])) return trim(preg_replace('/^urn:ietf:params:acme:error:/', '', (string) ($challenge['error']['type'] ?? '')) . ' - ' . ($challenge['error']['detail'] ?? ''), ' -');

        return 'status ' . ($authorization['status'] ?? '?') . ' ' . json_encode($authorization);
    }

    private function cleanupChallenges(array $challenges): void
    {
        foreach ($challenges as $challenge) if (($challenge['type'] ?? null) === 'http-01' && isset($challenge['filePath'])) @unlink($challenge['filePath']);
    }

    public function notifyChallenge(array $challenge): string
    {
        $trigger = $this->postAsJWS($challenge['url'], new \stdClass);
        if (!in_array($trigger['status'], [200, 202])) throw new \Exception("Notify challenge failed: " . self::problem($trigger));
        return $trigger['body'];
    }

    public function challengeAuth(string $authUrl, int $tries = 1): array
    {
        $check    = $this->postAsGet($authUrl);
        $adata    = json_decode($check['body'], true);
        $status   = $adata['status'] ?? null;
        $response = ['message' => 'ok', 'status' => true, 'tries' => $tries];

        if ($status === null) throw new \Exception("Authorization status is null: " . self::problem($check));
        // try again.
        if ($status === 'pending' || $status === 'processing') {
            if ($tries >= 20) throw new \Exception("Authorization still pending after $tries tries");
            sleep(5);
            return $this->challengeAuth($authUrl, $tries + 1);
        }

        # Only `valid` is a pass. `deactivated`, `expired` and `revoked` were let
        # through as success and the order then failed at finalize, far from the
        # reason.
        if ($status !== 'valid') {
            $response['status']  = false;
            $response['adata']   = $adata;
            $response['message'] = "Authorization $status";
        }

        return $response;
    }

    /**
     * Read an order until it leaves the given states.
     *
     * @param string $url
     * @param array  $while States to keep waiting through.
     * @param int    $tries
     * @return array The order object.
     */
    private function waitForOrder(string $url, array $while, int $tries = 10): array
    {
        for ($i = 1; ; $i++) {
            $res   = $this->postAsGet($url);
            $order = json_decode($res['body'], true);
            if (!is_array($order) || !isset($order['status'])) throw new \Exception("Cannot read order: " . self::problem($res));
            if (!in_array($order['status'], $while, true) || $i >= $tries) return $order;
            sleep(min(2 * $i, 6));
        }
    }

    public function finalize(array $order, array $domains): array
    {
        // single SAN certificate: one private key + one CSR covering every domain, stored under the primary.
        $primary    = $domains[0];
        $primaryDir = $order['domains'][$primary] ?? null;
        if (!$primaryDir) throw new \Exception("Primary domain dir not prepared: $primary");

        # A new key and CSR for every issuance. The old code reused domain.csr.pem
        # when one existed, so an order whose domains differed from the first one
        # (renewAll() renewed only the primary of a multi-domain certificate) was
        # refused at finalize - and kept being refused until storage/AutoSSL was
        # deleted. Written beside the live pair and swapped in by getCertificate()
        # only once the certificate is here: a renewal that fails half-way leaves
        # the installed key matching the installed certificate.
        $domainKey = $primaryDir . '/private.key.new';
        $csrPath   = $primaryDir . '/domain.csr.pem';
        $this->generateDomainKeyAndCSR($domains, $domainKey, $csrPath);

        $csrPem = file_get_contents($csrPath);
        $csrDer = $this->pemToDer($csrPem);
        $csr64  = self::base64url($csrDer);

        $finalize = $order['finalize'] ?? ($order['order']['finalize'] ?? null);
        if (!$finalize) throw new \Exception("No finalize URL");

        # The authorizations being valid does not make the order ready at that
        # instant: finalizing a moment too early was a 403 orderNotReady - the
        # "sometimes it fails" of an otherwise correct run.
        if (!empty($order['location'])) {
            $state = $this->waitForOrder($order['location'], ['pending']);
            if (!in_array($state['status'], ['ready', 'processing', 'valid'], true)) throw new \Exception("Order is {$state['status']}, not ready to finalize: " . json_encode($state['error'] ?? $state));
        }

        $finalizeResp = $this->postAsJWS($finalize, ['csr' => $csr64]);
        if (!in_array($finalizeResp['status'], [200, 202])) throw new \Exception("Finalize failed: " . self::problem($finalizeResp));

        # What renewAll() needs to renew this the same way: every domain, the CA.
        file_put_contents($primaryDir . '/meta.json.new', json_encode([
            'domains' => array_values($domains),
            'ca'      => $this->directoryUrl,
            'account' => $this->account ?? null,
            'issued'  => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return compact('domainKey');
    }

    public function getCertificate(array $order, string $domainKey, int $tries = 1): array
    {
        $odata = $this->waitForOrder($order['location'], ['pending', 'ready', 'processing'], 10);
        $tries = max($tries, 1);
        if (($odata['status'] ?? '') === 'invalid') return ['status' => false, 'tries' => $tries, 'message' => 'Order invalid: ' . json_encode($odata['error'] ?? $odata)];

        $certUrl = $odata['certificate'] ?? null;
        if (!$certUrl) return ['status' => false, 'tries' => $tries, 'message' => "certificate url not ready (order {$odata['status']})."];

        // download the full chain.
        $certGet = $this->postAsGet($certUrl);
        if ($certGet['status'] !== 200) throw new \Exception("Failed to download certificate: " . self::problem($certGet));

        // split leaf certificate from the CA bundle on the END CERTIFICATE boundary.
        $marker = "-----END CERTIFICATE-----";
        $pos    = strpos($certGet['body'], $marker);
        if ($pos === false) throw new \Exception("Invalid certificate response");
        $leaf    = trim(substr($certGet['body'], 0, $pos + strlen($marker)));
        $bundle  = trim(substr($certGet['body'], $pos + strlen($marker)));

        // persist to the primary domain folder (renewAll / getDaysLeftFromBundle read these back).
        # The new key goes live with its certificate, and not before.
        $dir  = dirname($domainKey);
        $live = $dir . '/private.key';
        if ($domainKey !== $live && is_file($domainKey)) rename($domainKey, $live);
        if (is_file("$dir/meta.json.new")) rename("$dir/meta.json.new", "$dir/meta.json");
        $private = file_get_contents($live);

        file_put_contents($dir . '/certificate.crt', $leaf);
        file_put_contents($dir . '/ca_bundle.key', $bundle);

        return ['status' => true, 'getCertUrlTries' => $tries, 'certificate' => $leaf, 'ca_bundle' => $bundle, 'private' => $private];
    }

    public function issue(array $domains, string $challenge_type = "http-01"): array
    {
        // dns-01 needs manual TXT publishing between requests, so it cannot be fully automated here.
        if ($challenge_type === 'dns-01') throw new \Exception("dns-01 cannot be issued automatically (manual TXT publishing required). Use newOrder/challenge/notifyChallenge/challengeAuth/finalize/getCertificate step by step.");

        #region order and challenge
        $order      = $this->newOrder($domains);
        $challenges = $this->challenge($order['authorizations'], $challenge_type);

        # The challenge files come down however this ends - a notify that throws, an
        # authorization still pending after the last poll - not only on the paths
        # that used to remember to.
        $authTries = [];
        try {
            // publish + notify every challenge first, then poll each authorization.
            foreach ($challenges as $challenge) {
                $this->publishChallenge($challenge);
                $this->notifyChallenge($challenge);
            }

            foreach ($order['authorizations'] as $auth) {
                $challengeAuth = $this->challengeAuth($auth['url']);
                $authTries[$auth['identifier']] = $challengeAuth['tries'];
                if (!$challengeAuth['status']) throw new \Exception("Authorization failed for " . $auth['identifier'] . ": " . self::authorizationError($challengeAuth['adata'] ?? []));
            }
        } finally {
            $this->cleanupChallenges($challenges);
        }
        #endregion

        $finalize = $this->finalize($order, $domains);
        $pollCert = $this->getCertificate($order, $finalize['domainKey']);
        if (!$pollCert['status']) throw new \Exception($pollCert['message'] ?? 'Certificate retrieval failed');

        return ['details' => ['getCertUrlTries' => $pollCert['getCertUrlTries'], 'authTries' => $authTries], 'cert' => $pollCert['certificate'], 'ca_bundle' => $pollCert['ca_bundle'], 'private' => $pollCert['private']];
    }

    /**
     * Every name a stored certificate covers: meta.json when the certificate was
     * issued by this version, else the subjectAltName of certificate.crt, else
     * the folder's own name.
     *
     * @param string $path A domain folder.
     * @return array
     */
    private function certificateDomains(string $path): array
    {
        $meta = json_decode((string) @file_get_contents("$path/meta.json"), true);
        if (!empty($meta['domains'])) return array_values($meta['domains']);

        $cert = @openssl_x509_parse((string) @file_get_contents("$path/certificate.crt"));
        if (!empty($cert['extensions']['subjectAltName'])) {
            preg_match_all('/DNS:([^,\s]+)/', $cert['extensions']['subjectAltName'], $m);
            if ($m[1]) {
                # The folder's own name first: finalize() stores under the first domain.
                $own = preg_replace('/^wildcard\./', '*.', basename($path));
                return array_values(array_unique(array_merge(in_array($own, $m[1], true) ? [$own] : [], $m[1])));
            }
        }

        return [preg_replace('/^wildcard\./', '*.', basename($path))];
    }

    /**
     * The instance that renews a certificate: this one when the certificate came
     * from this CA, else one for the CA that issued it (its oldest valid account).
     *
     * @param string|null $ca
     * @param array       $instances Built so far, by CA.
     * @return self
     */
    private function forCa(?string $ca, array &$instances): self
    {
        if ($ca === null || $ca === $this->directoryUrl) return $this;
        return $instances[$ca] ??= new self($ca, $this->openSSLConfig['config'] ?? null);
    }

    public function renewAll(): void
    {
        # A multi-domain certificate is stored under its first domain; prepareDomain()
        # also made folders for the others, holding nothing. Those were renewed on
        # their own - one name each, through checkSSL() of the live site - and the
        # multi-domain certificate itself was renewed with its first name only, its
        # other names dropped. Names already covered by a stored certificate are
        # skipped here; the certificate is renewed with all of its names.
        $covered = [];
        foreach ($this->list() as $path)
            if (is_file("$path/certificate.crt")) foreach (array_slice($this->certificateDomains($path), 1) as $name) $covered[preg_replace('/^\*\./', 'wildcard.', $name)] = basename($path);

        $instances = [];

        foreach ($this->list() as $path) {
            $domain = basename($path);

            if (isset($covered[$domain]) && !is_file("$path/certificate.crt")) continue;

            // wildcard domains use dns-01, which needs manual TXT publishing — cannot auto-renew.
            // The folder is what is being read, and prepareDomain() names it after the
            // domain with the `*.` rewritten to `wildcard.` — an asterisk is not a legal
            // directory name on Windows and awkward everywhere else. So looking for one
            // here never matched, and every wildcard fell through to be renewed under
            // the literal name `wildcard.example.com`, which is not a domain at all.
            if (strpos($domain, '*') !== false || str_starts_with($domain, 'wildcard.')) {
                echo "Skipping " . preg_replace('/^wildcard\./', '*.', $domain) . " (wildcard, dns-01 manual renewal)\n";
                continue;
            }

            // Reading the expiry can throw on its own: an empty or truncated
            // certificate.crt, or a domain that no longer answers on 443 for checkSSL()
            // to ask. Outside the try below, one such folder ended the whole round — and
            // renewAll() is what cron calls, so every domain after it in the listing
            // silently stopped being renewed until someone read the cron mail.
            $leaf = "$path/certificate.crt";

            try {
                $days = file_exists($leaf) ? $this->getDaysLeftFromBundle($leaf) : $this->checkSSL($domain)['days_left'];
            } catch (\Exception $e) {
                echo "Cannot read expiry for $domain: " . $e->getMessage() . "\n";
                continue;
            }

            if ($days < 20) {
                $names = $this->certificateDomains($path);
                $meta  = json_decode((string) @file_get_contents("$path/meta.json"), true);
                $ca    = $meta['ca'] ?? null;

                # A wildcard among the names needs dns-01 - manual, as above.
                if (array_filter($names, fn($n) => str_starts_with($n, '*.'))) {
                    echo "Skipping $domain (covers a wildcard, dns-01 manual renewal)\n";
                    continue;
                }

                echo "Renewing: " . implode(', ', $names) . " ($days days left" . ($ca && $ca !== $this->directoryUrl ? ', at ' . parse_url($ca, PHP_URL_HOST) : '') . ")\n";
                try {
                    $this->forCa($ca, $instances)->issue($names);
                    echo "Renewed $domain\n";
                } catch (\Throwable $e) {
                    echo "Failed to renew $domain: " . $e->getMessage() . "\n";
                }
            } else {
                echo "$domain OK ($days days)\n";
            }
        }
    }

    private function generateDomainKeyAndCSR(array $domains, string $keyPath, string $csrPath): void
    {
        $res = openssl_pkey_new($this->openSSLConfig);
        if ($res === false) throw new \RuntimeException("Private key generation failed: " . openssl_error_string());

        openssl_pkey_export($res, $pem, null, $this->openSSLConfig);
        file_put_contents($keyPath, $pem);
        chmod($keyPath, 0600);

        // SAN certificate: emit a temporary openssl config carrying every domain as subjectAltName.
        $san     = implode(',', array_map(fn($d) => 'DNS:' . $d, $domains));
        $tmpConf = tempnam(sys_get_temp_dir(), 'san');
        file_put_contents($tmpConf, "[req]\ndistinguished_name = req_distinguished_name\nreq_extensions = v3_req\n[req_distinguished_name]\n[v3_req]\nbasicConstraints = CA:FALSE\nkeyUsage = nonRepudiation, digitalSignature, keyEncipherment\nsubjectAltName = $san\n");

        $configargs = [
            'digest_alg'       => 'sha256',
            'config'           => $tmpConf,
            'req_extensions'   => 'v3_req',
            'private_key_bits' => $this->openSSLConfig['private_key_bits'],
            'private_key_type' => $this->openSSLConfig['private_key_type'],
        ];

        $csrRes = openssl_csr_new(['commonName' => $domains[0]], $res, $configargs);
        @unlink($tmpConf);

        if ($csrRes === false) throw new \RuntimeException("CSR generation failed: " . openssl_error_string());

        openssl_csr_export($csrRes, $csrPem);
        file_put_contents($csrPath, $csrPem);
        chmod($csrPath, 0600);
    }

    private function pemToDer(string $pem): string
    {
        $str = preg_replace('#-+BEGIN CERTIFICATE REQUEST-+#', '', $pem);
        $str = preg_replace('#-+END CERTIFICATE REQUEST-+#', '', $str);
        $str = str_replace(["\r", "\n"], '', $str);
        return base64_decode($str);
    }

    private function getDaysLeftFromBundle(string $certFile): int
    {
        $certContent = file_get_contents($certFile);
        if ($certContent === false || $certContent === '') throw new \Exception("Cannot read certificate file: $certFile");

        $cert = openssl_x509_read($certContent);
        if (!$cert) throw new \Exception("Cannot parse certificate: $certFile");

        $certData = openssl_x509_parse($cert);
        if (!isset($certData['validTo_time_t'])) throw new \Exception("Certificate has no expiry date: $certFile");
        return (int) floor(($certData['validTo_time_t'] - time()) / 86400);
    }
}
