<?php
declare(strict_types=1);

/**
 * QuantTrend AI v12.0.7 — Same-Origin SSRF-Hardened Public Data Relay
 */
final class SafeHttpClient
{
    public const USER_AGENT = 'QuantTrend 12.0.7';
    private const MAX_BYTES = 8388608; // 8 MB
    private const MAX_REDIRECTS = 3;

    /** @var string[] */
    private array $allowlist;

    /** @param string[] $allowlist */
    public function __construct(array $allowlist = [])
    {
        $defaultHosts = [
            'query1.finance.yahoo.com',
            'query2.finance.yahoo.com',
            'stooq.com',
            'api.binance.com',
            'data-api.ecb.europa.eu',
            'api.fiscaldata.treasury.gov',
            'api.frankfurter.app',
            'api.gdeltproject.org',
            'earthquake.usgs.gov',
            'api.bls.gov',
            'ec.europa.eu',
            'www.tagesschau.de',
            'www.ecb.europa.eu',
        ];
        $this->allowlist = array_values(array_unique(array_map('strtolower', $allowlist ?: $defaultHosts)));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blockedExact = ['169.254.169.254', '169.254.170.2', '100.100.100.200', '192.0.0.192'];
        return !in_array($ip, $blockedExact, true);
    }

    /** @return string[] */
    private function resolveAndValidateHost(string $host): array
    {
        $normalized = rtrim(strtolower(trim($host)), '.');
        if ($normalized === '' || !in_array($normalized, $this->allowlist, true)) {
            throw new RuntimeException('Host nicht auf der Allowlist: ' . $host);
        }

        $ips = [];
        $recordsA = @dns_get_record($normalized, DNS_A);
        if (is_array($recordsA)) {
            foreach ($recordsA as $rec) {
                if (!empty($rec['ip'])) {
                    $ips[] = (string)$rec['ip'];
                }
            }
        }
        $recordsAAAA = @dns_get_record($normalized, DNS_AAAA);
        if (is_array($recordsAAAA)) {
            foreach ($recordsAAAA as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string)$rec['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $fallback = gethostbynamel($normalized);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }
        if ($ips === []) {
            throw new RuntimeException('DNS-Auflösung fehlgeschlagen für: ' . $normalized);
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('Nicht-öffentliche Ziel-IP blockiert: ' . $ip);
            }
        }
        return array_values(array_unique($ips));
    }

    public function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Ungültige URL.');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('Nur HTTPS-URLs erlaubt.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Userinfo in URL nicht erlaubt.');
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new InvalidArgumentException('Nur Standard-HTTPS-Port 443 erlaubt.');
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '' || str_ends_with($host, '.')) {
            throw new InvalidArgumentException('Ungültiger oder Trailing-Dot-Host.');
        }
        $ips = $this->resolveAndValidateHost($host);
        return ['host' => strtolower($host), 'ip' => $ips[0]];
    }

    public function fetch(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL-Erweiterung fehlt.');
        }

        $currentUrl = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->validateUrl($currentUrl);
            $host = $target['host'];
            $pinnedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];

            $ch = curl_init($currentUrl);
            $body = '';
            $headersOut = [];

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECT_TO     => ["{$host}:443:{$pinnedIp}:443"],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headersOut): int {
                    $len = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headersOut[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body): int {
                    $len = strlen($chunk);
                    if (strlen($body) + $len > self::MAX_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return $len;
                },
            ]);

            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
            } elseif (defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }

            $execOk = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($execOk === false && $status === 0) {
                return ['ok' => false, 'status' => 0, 'error' => $err ?: 'cURL-Fehler', 'body' => '', 'headers' => $headersOut];
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headersOut['location'])) {
                $next = $headersOut['location'];
                if (!str_starts_with($next, 'https://')) {
                    throw new RuntimeException('Unsicherer Redirect blockiert.');
                }
                $currentUrl = $next;
                continue;
            }

            return [
                'ok'        => $status >= 200 && $status < 300,
                'status'    => $status,
                'body'      => $body,
                'headers'   => $headersOut,
                'final_url' => $currentUrl,
            ];
        }

        throw new RuntimeException('Zu viele Redirects.');
    }
}

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $targetUrl = (string)($_GET['url'] ?? '');
    if ($targetUrl === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Parameter url fehlt.']);
        exit;
    }

    try {
        $client = new SafeHttpClient();
        $res = $client->fetch($targetUrl);
        if (!$res['ok']) {
            http_response_code($res['status'] >= 400 ? $res['status'] : 502);
            echo json_encode([
                'ok'     => false,
                'status' => $res['status'],
                'error'  => $res['error'] ?? ('Upstream HTTP ' . $res['status']),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode([
            'ok'           => true,
            'relay'        => SafeHttpClient::USER_AGENT,
            'status'       => $res['status'],
            'content_type' => $res['headers']['content-type'] ?? 'application/octet-stream',
            'body'         => $res['body'],
        ], JSON_UNESCAPED_SLASHES);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
}
