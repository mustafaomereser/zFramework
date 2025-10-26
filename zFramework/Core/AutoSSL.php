<?php

namespace zFramework\Core;

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
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawHeaders = substr($res, 0, $hsize);
        $body = substr($res, $hsize);
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

    private function postAsJWS(string $url, $payload, ?string $kid = null): array
    {
        return $this->httpRequest($url, 'POST', $this->signJWS($url, $payload, $kid), ['Content-Type: application/jose+json']);
    }

    public function ensureAccount(): string
    {
        if ($this->kid) return $this->kid;
        $url     = $this->dir['newAccount'];
        $payload = ['termsOfServiceAgreed' => true];
        $resp    = $this->postAsJWS($url, $payload, null);
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

    public function issue(string $domain): bool
    {
        $domain = trim($domain);
        if ($domain === '') throw new \Exception("Domain empty");
        $domainDir = $this->sslPath . '/' . $domain;
        if (!is_dir($domainDir)) mkdir($domainDir, 0777, true);

        $kid = $this->ensureAccount();

        // create order
        $orderUrl = $this->dir['newOrder'];
        $resp     = $this->postAsJWS($orderUrl, ['identifiers' => [['type' => 'dns', 'value' => $domain]]], $kid);
        if (!in_array($resp['status'], [201, 200])) throw new \Exception("newOrder failed: " . $resp['body']);
        $order = json_decode($resp['body'], true);
        $orderLocation = $resp['headers']['Location'] ?? $resp['headers']['location'] ?? null;
        if (!$orderLocation) throw new \Exception("No order location");

        $authUrls = $order['authorizations'] ?? [];
        if (count($authUrls) === 0) throw new \Exception("No authorizations in order");
        $authUrl = $authUrls[0];

        // GET authorization
        $authResp      = $this->httpRequest($authUrl, 'GET');
        $auth          = json_decode($authResp['body'], true);
        $httpChallenge = null;

        foreach ($auth['challenges'] as $c) if ($c['type'] === 'http-01') {
            $httpChallenge = $c;
            break;
        }

        if (!$httpChallenge) throw new \Exception("No http-01 challenge available");

        $token   = $httpChallenge['token'];
        $thumb   = $this->jwkThumbprint($this->getJWK());
        $keyAuth = $token . '.' . $thumb;

        // write challenge file
        $challengeFile = $this->webChallengePath . '/' . $token;
        if (file_put_contents($challengeFile, $keyAuth) === false) throw new \Exception("Cannot write challenge file");
        chmod($challengeFile, 0644);

        // notify ACME to validate the challenge
        $trigger = $this->postAsJWS($httpChallenge['url'], new \stdClass(), $kid);
        if (!in_array($trigger['status'], [200, 202])) throw new \Exception("Trigger challenge failed: " . $trigger['body']);

        // poll authorization until valid
        $tries = 0;
        $valid = false;
        while ($tries < 30) {
            sleep(2);
            $check  = $this->httpRequest($authUrl, 'GET');
            $adata  = json_decode($check['body'], true);
            $status = $adata['status'] ?? null;
            if ($status === 'valid') {
                $valid = true;
                break;
            }
            if ($status === 'invalid') throw new \Exception("Authorization invalid: " . json_encode($adata, JSON_PRETTY_PRINT));
            $tries++;
        }
        if (!$valid) throw new \Exception("Authorization did not become valid in time");

        // cleanup challenge file
        @unlink($challengeFile);

        // prepare CSR
        $domainKey = $domainDir . '/private.key';
        $csrPath   = $domainDir . '/domain.csr.pem';
        if (!file_exists($domainKey) || !file_exists($csrPath)) $this->generateDomainKeyAndCSR($domain, $domainKey, $csrPath);

        $csrPem = file_get_contents($csrPath);
        $csrDer = $this->pemToDer($csrPem);
        $csr64  = self::base64url($csrDer);

        $finalize = $order['finalize'] ?? null;
        if (!$finalize) throw new \Exception("No finalize URL");

        $finalizeResp = $this->postAsJWS($finalize, ['csr' => $csr64], $kid);
        if (!in_array($finalizeResp['status'], [200, 202])) throw new \Exception("Finalize failed: " . $finalizeResp['body']);

        // poll order until certificate URL
        $tries   = 0;
        $certUrl = null;
        while ($tries < 30) {
            sleep(2);
            $ordCheck = $this->httpRequest($orderLocation, 'GET');
            $odata = json_decode($ordCheck['body'], true);
            if (isset($odata['certificate'])) {
                $certUrl = $odata['certificate'];
                break;
            }
            if (($odata['status'] ?? '') === 'invalid') throw new \Exception("Order invalid: " . json_encode($odata, JSON_PRETTY_PRINT));
            $tries++;
        }
        if (!$certUrl) throw new \Exception("Certificate URL missing");

        // download certificate
        $certGet = $this->httpRequest($certUrl, 'GET');
        if ($certGet['status'] !== 200) throw new \Exception("Failed to download certificate");
        $certPem = $certGet['body'];

        file_put_contents($domainDir . '/certificate.pem', $certPem);
        file_put_contents($domainDir . '/ca_bundle.pem', $certPem);
        copy($domainKey, $domainDir . '/private.key');

        return true;
    }

    public function renewAll(): void
    {
        foreach ($this->list() as $domain) {
            $full   = "$domain/ca_bundle.pem";
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
