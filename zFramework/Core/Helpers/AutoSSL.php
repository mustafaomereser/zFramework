<?php

namespace zFramework\Core\Helpers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

    public function __construct(string $directoryUrl = self::STAGING, ?string $openSSLConfig = null)
    {
        global $storage_path;
        if (!is_null($openSSLConfig)) $this->openSSLConfig['config'] = $openSSLConfig;

        $this->sslPath          = $storage_path . "/AutoSSL";
        $this->directoryUrl     = $directoryUrl;
        $this->webChallengePath = public_dir('/.well-known/acme-challenge');
        $this->accountKeyPath   = $this->sslPath . '/account.key';

        if (!is_dir($this->sslPath)) mkdir($this->sslPath, 0755, true);
        if (!is_dir($this->webChallengePath)) mkdir($this->webChallengePath, 0755, true);

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
        $this->kid = null;
        unlink($this->sslPath . '/account.kid');
        unlink($this->sslPath . '/account.key');
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

        if (!in_array($res['status'], [201, 200])) throw new \Exception("newOrder failed: " . $res['body']);
        $order    = json_decode($res['body'], true);
        $location = $res['headers']['Location'] ?? $res['headers']['location'] ?? null;
        if (!$location) throw new \Exception("No order location");

        $authUrls = $order['authorizations'] ?? [];
        if (count($authUrls) === 0) throw new \Exception("No authorizations in order");

        // GET each authorization to collect its challenges (one authorization per identifier).
        $authorizations = [];
        foreach ($authUrls as $authUrl) {
            $authResp = $this->httpRequest($authUrl, 'GET');
            if ($authResp['status'] !== 200) throw new \Exception("Cannot load authorization: " . $authResp['body']);
            $adata = json_decode($authResp['body'], true);
            $authorizations[] = [
                'url'        => $authUrl,
                'identifier' => $adata['identifier']['value'] ?? null,
                'wildcard'   => $adata['wildcard'] ?? false,
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

    private function cleanupChallenges(array $challenges): void
    {
        foreach ($challenges as $challenge) if (($challenge['type'] ?? null) === 'http-01' && isset($challenge['filePath'])) @unlink($challenge['filePath']);
    }

    public function notifyChallenge(array $challenge): string
    {
        $trigger = $this->postAsJWS($challenge['url'], new \stdClass);
        if (!in_array($trigger['status'], [200, 202])) throw new \Exception("Notify challenge failed: " . $trigger['body']);
        return $trigger['body'];
    }

    public function challengeAuth(string $authUrl, int $tries = 1): array
    {
        $check    = $this->httpRequest($authUrl, 'GET');
        $adata    = json_decode($check['body'], true);
        $status   = $adata['status'] ?? null;
        $response = ['message' => 'ok', 'status' => true, 'tries' => $tries];

        if ($status === null) throw new \Exception("Authorization status is null");
        // try again.
        if ($status === 'pending') {
            if ($tries >= 20) throw new \Exception("Authorization still pending after $tries tries");
            sleep(5);
            return $this->challengeAuth($authUrl, $tries + 1);
        }

        if ($status === 'invalid') {
            $response['status']  = false;
            $response['adata']   = $adata;
            $response['message'] = 'Authorization invalid';
        }

        return $response;
    }

    public function finalize(array $order, array $domains): array
    {
        // single SAN certificate: one private key + one CSR covering every domain, stored under the primary.
        $primary    = $domains[0];
        $primaryDir = $order['domains'][$primary] ?? null;
        if (!$primaryDir) throw new \Exception("Primary domain dir not prepared: $primary");

        $domainKey = $primaryDir . '/private.key';
        $csrPath   = $primaryDir . '/domain.csr.pem';
        if (!file_exists($domainKey) || !file_exists($csrPath)) $this->generateDomainKeyAndCSR($domains, $domainKey, $csrPath);

        $csrPem = file_get_contents($csrPath);
        $csrDer = $this->pemToDer($csrPem);
        $csr64  = self::base64url($csrDer);

        $finalize = $order['finalize'] ?? ($order['order']['finalize'] ?? null);
        if (!$finalize) throw new \Exception("No finalize URL");

        $finalizeResp = $this->postAsJWS($finalize, ['csr' => $csr64]);
        if (!in_array($finalizeResp['status'], [200, 202])) throw new \Exception("Finalize failed: " . $finalizeResp['body']);

        return compact('domainKey');
    }

    public function getCertificate(array $order, string $domainKey, int $tries = 1): array
    {
        $ordCheck = $this->httpRequest($order['location'], 'GET');
        $odata    = json_decode($ordCheck['body'], true);
        if (($odata['status'] ?? '') === 'invalid') return ['status' => false, 'tries' => $tries, 'message' => 'can not get cert url.'];

        $certUrl = $odata['certificate'] ?? null;
        // order not yet ready: poll again with a guard against infinite recursion.
        if (!$certUrl) {
            if ($tries >= 10) return ['status' => false, 'tries' => $tries, 'message' => 'certificate url not ready.'];
            sleep(3);
            return $this->getCertificate($order, $domainKey, $tries + 1);
        }

        // download the full chain.
        $certGet = $this->httpRequest($certUrl, 'GET');
        if ($certGet['status'] !== 200) throw new \Exception("Failed to download certificate");

        // split leaf certificate from the CA bundle on the END CERTIFICATE boundary.
        $marker = "-----END CERTIFICATE-----";
        $pos    = strpos($certGet['body'], $marker);
        if ($pos === false) throw new \Exception("Invalid certificate response");
        $leaf    = trim(substr($certGet['body'], 0, $pos + strlen($marker)));
        $bundle  = trim(substr($certGet['body'], $pos + strlen($marker)));
        $private = file_get_contents($domainKey);

        // persist to the primary domain folder (renewAll / getDaysLeftFromBundle read these back).
        $dir = dirname($domainKey);
        file_put_contents($dir . '/certificate.crt', $leaf);
        file_put_contents($dir . '/ca_bundle.key', $bundle);

        return ['status' => true, 'getCertUrlTries' => $tries, 'certificate' => $leaf, 'ca_bundle' => $bundle, 'private' => $private];
    }

    public function issue(array $domains, string $challenge_type = "http-01"): array
    {
        // dns-01 needs manual TXT publishing between requests, so it cannot be fully automated here.
        if ($challenge_type === 'dns-01') throw new \Exception("dns-01 cannot be issued automatically (manual TXT publishing required). Use newOrder/challenge/notifyChallenge/challengeAuth/finalize/getCertificate step by step — see HomeController for an example.");

        #region order and challenge
        $order      = $this->newOrder($domains);
        $challenges = $this->challenge($order['authorizations'], $challenge_type);

        // publish + notify every challenge first, then poll each authorization.
        foreach ($challenges as $challenge) {
            $this->publishChallenge($challenge);
            $this->notifyChallenge($challenge);
        }

        $authTries = [];
        foreach ($order['authorizations'] as $auth) {
            $challengeAuth = $this->challengeAuth($auth['url']);
            $authTries[$auth['identifier']] = $challengeAuth['tries'];
            if (!$challengeAuth['status']) {
                $this->cleanupChallenges($challenges);
                throw new \Exception("Authorization failed for " . $auth['identifier'] . ": " . json_encode($challengeAuth['adata'] ?? []));
            }
        }

        $this->cleanupChallenges($challenges);
        #endregion

        $finalize = $this->finalize($order, $domains);
        $pollCert = $this->getCertificate($order, $finalize['domainKey']);
        if (!$pollCert['status']) throw new \Exception($pollCert['message'] ?? 'Certificate retrieval failed');

        return ['details' => ['getCertUrlTries' => $pollCert['getCertUrlTries'], 'authTries' => $authTries], 'cert' => $pollCert['certificate'], 'ca_bundle' => $pollCert['ca_bundle'], 'private' => $pollCert['private']];
    }

    public function renewAll(): void
    {
        foreach ($this->list() as $path) {
            $domain = basename($path);

            // wildcard domains use dns-01, which needs manual TXT publishing — cannot auto-renew.
            if (strpos($domain, '*') !== false) {
                echo "Skipping $domain (wildcard, dns-01 manual renewal)\n";
                continue;
            }

            $leaf = "$path/certificate.crt";
            $days = file_exists($leaf) ? $this->getDaysLeftFromBundle($leaf) : $this->checkSSL($domain)['days_left'];
            if ($days < 20) {
                echo "Renewing: $domain ($days days left)\n";
                try {
                    $this->issue([$domain]);
                    echo "Renewed $domain\n";
                } catch (\Exception $e) {
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
