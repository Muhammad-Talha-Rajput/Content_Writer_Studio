<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

class PublicSourceFetcher
{
    public function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($ip, ':')) {
            return IpUtils::checkIp($ip, '2000::/3') && !IpUtils::checkIp($ip, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
        }
        return !IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }

    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }

    public function target(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)
            || !in_array($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80), [80, 443], true)) {
            throw new RuntimeException('Use a public HTTP or HTTPS URL on port 80 or 443 without credentials.');
        }
        $addresses = $this->resolve($host);
        if (!$addresses || count($addresses) > 32) throw new RuntimeException('The website could not be resolved safely.');
        foreach ($addresses as $ip) {
            if (!$this->publicIp($ip)) throw new RuntimeException('Private, reserved, and local network addresses cannot be retrieved.');
        }
        return [$host, $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), $addresses[0]];
    }

    protected function request(string $url, string $host, int $port, string $ip): array
    {
        $body = ''; $headers = []; $headerBytes = 0;
        $curl = curl_init($url);
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt_array($curl, [CURLOPT_RESOLVE => ["$host:$port:$pin"], CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'GrandsDigitalContentStudio/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html, text/plain', 'Accept-Encoding: identity'],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 2097152) return 0;
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers, &$headerBytes) {
                $headerBytes += strlen($line); if ($headerBytes > 65536) return 0;
                if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))] = trim($value); }
                return strlen($line);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $actual = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
            if ($ok === false || @inet_pton($actual) !== @inet_pton($ip)) {
                \Illuminate\Support\Facades\Log::warning('Public source connection failed', ['curl_code' => curl_errno($curl), 'error' => curl_error($curl)]);
                throw new RuntimeException('The website timed out, exceeded the size limit, or could not be retrieved securely.');
            }
            return [$status, $headers, $body];
        } finally { curl_close($curl); }
    }

    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            [$host, $port, $ip] = $this->target($url);
            [$status, $headers, $body] = $this->request($url, $host, $port, $ip);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (empty($headers['location']) || $hop === 3) throw new RuntimeException('The website has too many redirects.');
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($headers['location']));
                continue;
            }
            if ($status < 200 || $status >= 300) throw new RuntimeException('The website did not return a readable page.');
            $mime = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
            if (!in_array($mime, ['text/html', 'text/plain', 'application/xhtml+xml'], true)
                || !in_array(strtolower($headers['content-encoding'] ?? 'identity'), ['identity', ''], true)) {
                throw new RuntimeException('Only uncompressed HTML and plain-text web pages are supported. Upload documents as files.');
            }
            return ['url' => $url, 'body' => $body, 'mime' => $mime];
        }
        throw new RuntimeException('The website could not be retrieved.');
    }
}
