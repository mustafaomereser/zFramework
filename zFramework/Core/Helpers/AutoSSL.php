<?php

namespace zFramework\Core\Helpers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use zFramework\Core\Facades\Response;
use ZipArchive;

class AutoSSL
{
    public const STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    public const PROD    = 'https://acme-v02.api.letsencrypt.org/directory';

    private string $sslPath;
    private string $webChallengePath;
    private string $accountKeyPath;
    private string $directoryUrl;
    private $accountKeyRes;
    private array $dir           = [];
    private array $openSSLConfig = ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    private ?string $kid = null;

    public function __construct(string $directoryUrl = self::STAGING, null|string $openSSLConfig = null)
    {
        if (!is_null($openSSLConfig)) $this->openSSLConfig['config'] = $openSSLConfig;

        $this->sslPath          = FRAMEWORK_PATH . "/Caches/AutoSSL";
        $this->directoryUrl     = $directoryUrl;
        $this->webChallengePath = public_dir('/.well-known/acme-challenge');
        $this->accountKeyPath   = $this->sslPath . '/account.key';

        if (!is_dir($this->sslPath)) mkdir($this->sslPath, 0777, true);
        if (!is_dir($this->webChallengePath)) mkdir($this->webChallengePath, 0777, true);

        if (!file_exists($this->accountKeyPath)) $this->generateAccountKey();
        $this->loadAccountKey();
        $this->loadDirectory();

        // load stored kid if exists
        $kidFile = $this->sslPath . '/account.kid';
        if (file_exists($kidFile)) $this->kid = trim(file_get_contents($kidFile));
        else $this->kid = $this->ensureAccount();
    }

    private function httpRequest(string $url, string $method = 'GET', $body = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
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

        $hdrs = [];
        foreach (explode("\r\n", $rawHeaders) as $line) if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[trim($k)] = trim($v);
        }

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
        openssl_pkey_export($key, $pem, null, $this->openSSLConfig);
        file_put_contents($this->accountKeyPath, $pem);
        chmod($this->accountKeyPath, 0600);
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

    private function getNonce(): string
    {
        $url = $this->dir['newNonce'] ?? ($this->directoryUrl . '/new-nonce');
        $res = $this->httpRequest($url, 'HEAD');
        foreach ($res['headers'] as $k => $v) if (strtolower($k) === 'replay-nonce') return $v;
        throw new \Exception("No Replay-Nonce received");
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

    private function postAsJWS(string $url, $payload): array
    {
        return $this->httpRequest($url, 'POST', $this->signJWS($url, $payload, $this->kid), ['Content-Type: application/jose+json']);
    }

    public function ensureAccount(): string
    {
        if ($this->kid) return $this->kid;
        $url     = $this->dir['newAccount'];
        $payload = ['termsOfServiceAgreed' => true];
        $resp    = $this->postAsJWS($url, $payload);
        if (!in_array($resp['status'], [200, 201])) throw new \Exception("Account creation failed: " . $resp['body']);

        $loc = $resp['headers']['Location'] ?? $resp['headers']['location'] ?? null;
        if (!$loc) throw new \Exception("No account Location header");
        $this->kid = $loc;
        file_put_contents($this->sslPath . '/account.kid', $loc);
        return $loc;
    }

    public function unlinkAccount(): void
    {
        unlink($this->sslPath . '/account.kid');
        unlink($this->sslPath . '/account.key');
    }

    public function checkSSL(string $domain): array
    {
        $ctx    = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
        $client = stream_socket_client("ssl://$domain:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        $cont   = stream_context_get_params($client);
        $cert   = openssl_x509_parse($cont["options"]["ssl"]["peer_certificate"]);
        return ['domain' => $cert['subject']['CN'], 'givenby' => $cert['issuer']['O'], 'last_date' => date('Y-m-d H:i:s', $cert['validTo_time_t']), 'days_left' => floor(($cert['validTo_time_t'] - time()) / 86400)];
    }

    public function list(): array
    {
        return glob($this->sslPath . '/*', GLOB_ONLYDIR);
    }

    public function download(string $domain): array
    {
        $folder  = $this->sslPath . "/$domain";
        if (!is_dir($folder)) throw new \Exception("Domain is not exists");

        $zip      = new ZipArchive();
        $temp_zip = tempnam(sys_get_temp_dir(), 'zip');
        if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) exit("Zip cannot open!");
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
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

    public function newOrder($domain)
    {
        $res = $this->postAsJWS($this->dir['newOrder'], ['identifiers' => [['type' => 'dns', 'value' => $domain]]]);
        if (!in_array($res['status'], [201, 200])) throw new \Exception("newOrder failed: " . $res['body']);
        $order    = json_decode($res['body'], true);
        $location = $res['headers']['Location'] ?? $res['headers']['location'] ?? null;
        if (!$location) throw new \Exception("No order location");

        $authUrls = $order['authorizations'] ?? [];
        if (count($authUrls) === 0) throw new \Exception("No authorizations in order");
        $authURL = $authUrls[0];

        // GET authorization
        $authResp = $this->httpRequest($authURL, 'GET');
        $body     = json_decode($authResp['body'], true);
        return compact('body', 'authURL', 'order', 'location');
    }

    public function challenge($challenges)
    {
        $getChallenge = (function () use ($challenges) {
            foreach ($challenges as $challenge) if ($challenge['type'] === 'http-01') return $challenge;
            return false;
        })();
        if (!$getChallenge) throw new \Exception("No http-01 challenge available");

        $url   = $getChallenge['url'];
        $token = $getChallenge['token'];
        $key   = $token . '.' . $this->jwkThumbprint($this->getJWK());

        return compact('key', 'url', 'token');
    }

    public function notifyChallenge($challenge)
    {
        $trigger = $this->postAsJWS($challenge['url'], new \stdClass);
        if (!in_array($trigger['status'], [200, 202])) throw new \Exception("Notify challenge failed: " . $trigger['body']);
        return $trigger['body'];
    }

    public function challengeAuth($order, $tries = 1)
    {
        $check    = $this->httpRequest($order['authURL'], 'GET');
        $adata    = json_decode($check['body'], true);
        $status   = $adata['status'] ?? null;
        $response = ['message' => 'ok', 'status' => true, 'tries' => $tries];

        if ($status === null) throw new \Exception("Authorization status is null");
        // try again.
        if ($status === 'pending') {
            sleep(5);
            return $this->challengeAuth($order, $tries + 1);
        }

        if ($status === 'invalid') {
            $response['status']  = false;
            $response['adata']   = $adata;
            $response['message'] = 'Authorization invalid';
        }

        return $response;
    }

    public function finalize($order, $domain, $domainDir)
    {
        $domainKey = $domainDir . '/private.key';
        $csrPath   = $domainDir . '/domain.csr.pem';
        if (!file_exists($domainKey) || !file_exists($csrPath)) $this->generateDomainKeyAndCSR($domain, $domainKey, $csrPath);

        $csrPem = file_get_contents($csrPath);
        $csrDer = $this->pemToDer($csrPem);
        $csr64  = self::base64url($csrDer);

        $finalize = $order['order']['finalize'] ?? null;
        if (!$finalize) throw new \Exception("No finalize URL");

        $finalizeResp = $this->postAsJWS($finalize, ['csr' => $csr64]);
        if (!in_array($finalizeResp['status'], [200, 202])) throw new \Exception("Finalize failed: " . $finalizeResp['body']);

        return compact('domainKey');
    }

    public function getCertificate($order, $domainKey, $tries = 1)
    {
        $ordCheck = $this->httpRequest($order['location'], 'GET');
        $odata    = json_decode($ordCheck['body'], true);
        if (isset($odata['certificate'])) $certUrl = $odata['certificate'];
        if (($odata['status'] ?? '') === 'invalid') return ['status' => false, 'tries' => $tries, 'message' => 'can not get cert url.'];

        // try again.
        if (!isset($certUrl)) return $this->getCertificate($order, $domainKey, $tries + 1);

        // download certificate
        $certGet = $this->httpRequest($certUrl, 'GET');
        if ($certGet['status'] !== 200) throw new \Exception("Failed to download certificate");
        $certPem = explode('

', $certGet['body']);

        return ['status' => true, 'getCertUrlTries' => $tries, 'certificate' => $certPem[0], 'ca_bundle' => $certPem[1], 'private' => file_get_contents($domainKey)];
    }

    public function issue(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') throw new \Exception("Domain empty");
        $domainDir = $this->sslPath . '/' . $domain;
        if (!is_dir($domainDir)) mkdir($domainDir, 0777, true);

        #region order and challenge
        $order     = $this->newOrder($domain);
        $challenge = $this->challenge($order['body']['challenges']);

        $challengeFile = $this->webChallengePath . '/' . $challenge['token'];
        if (file_put_contents($challengeFile, $challenge['key']) === false) throw new \Exception("Cannot write challenge file");
        chmod($challengeFile, 0644);

        $this->notifyChallenge($challenge);
        $challengeAuth = $this->challengeAuth($order);
        // do notify and challengeAuth remove after challenge file.
        @unlink($challengeFile);

        if (!$challengeAuth['status']) die(Response::json($challengeAuth, JSON_PRETTY_PRINT));
        #endregion

        $finalize = $this->finalize($order, $domain, $domainDir);
        $pollCert = $this->getCertificate($order, $finalize['domainKey']);
        if (!$pollCert['status']) die(Response::json($pollCert, JSON_PRETTY_PRINT));

        file_put_contents($domainDir . '/certificate.key', $pollCert['certificate']);
        file_put_contents($domainDir . '/ca_bundle.key', $pollCert['ca_bundle']);
        file_put_contents($domainDir . '/private.key', $pollCert['private']);

        return ['details' => ['getCertUrlTries' => $pollCert['getCertUrlTries'], 'challengeAuthTries' => $challengeAuth['tries']], 'cert' => $pollCert['certificate'], 'ca_bundle' => $pollCert['ca_bundle'], 'private' => $pollCert['private']];
    }

    public function renewAll(): void
    {
        foreach ($this->list() as $domain) {
            $full   = "$domain/ca_bundle.key";
            $domain = basename($domain);
            $days   = file_exists($full) ? $this->getDaysLeftFromBundle($full) : $this->checkSSL($domain)['days_left'];
            if ($days < 20) {
                echo "Renewing: $domain ($days days left)\n";
                try {
                    $this->issue($domain);
                    echo "Renewed $domain\n";
                } catch (\Exception $e) {
                    echo "Failed to renew $domain: " . $e->getMessage() . "\n";
                }
            } else {
                echo "$domain OK ($days days)\n";
            }
        }
    }

    private function generateDomainKeyAndCSR(string $domain, string $keyPath, string $csrPath): void
    {
        $res = openssl_pkey_new($this->openSSLConfig);
        if ($res === false) throw new \RuntimeException("Private key generation failed: " . openssl_error_string());

        openssl_pkey_export($res, $pem, null, $this->openSSLConfig);
        file_put_contents($keyPath, $pem);
        chmod($keyPath, 0600);

        $csrRes = openssl_csr_new(['commonName' => $domain], $res, ['digest_alg' => 'sha256'] + $this->openSSLConfig);

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
        if (!$certContent) return 0;

        $cert = openssl_x509_read($certContent);
        if (!$cert) return 0;

        $certData = openssl_x509_parse($cert);
        if (!isset($certData['validTo_time_t'])) return 0;
        return (int) floor(($certData['validTo_time_t'] - time()) / 86400);
    }
}
