<?php

/**
 * OVPN IP Fetcher
 * Scrapes status.ovpn.com to extract all VPN server cities,
 * converts them to DNS slugs, and resolves IPs via dig.
 *
 * Usage:
 *   php ovpn_cities.php                          # list cities as JSON (stdout)
 *   php ovpn_cities.php --dig                    # resolve IPs via dig
 *   php ovpn_cities.php --dig --output=out.json  # write JSON to file
 *   php ovpn_cities.php --dig -o out.json        # shorthand
 */

declare(strict_types=1);

define('MAX_CONSECUTIVE_MISSES', 5);
define('MAX_HOST_INDEX',         200);
define('DOH_BATCH_SIZE',         20);
define('DOH_URL',                'https://cloudflare-dns.com/dns-query');

function parseArgs(array $argv): array
{
    $opts = [
        'dig'    => false,
        'output' => null,
    ];

    $args = array_slice($argv, 1);

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if ($arg === '--dig') {
            $opts['dig'] = true;
        } elseif (str_starts_with($arg, '--output=')) {
            $opts['output'] = substr($arg, strlen('--output='));
        } elseif ($arg === '--output' || $arg === '-o') {
            $opts['output'] = $args[++$i] ?? null;
        }
    }

    return $opts;
}

/**
 * Print a status line to STDERR so it never pollutes JSON stdout/file output.
 */
function status(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

function fetchPage(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err) {
        throw new RuntimeException("cURL error: $err");
    }
    if ($code !== 200) {
        throw new RuntimeException("HTTP $code received from $url");
    }

    return $body;
}

function cityToSlug(string $city): string
{
    if (function_exists('transliterator_transliterate')) {
        $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $city);
        if ($slug !== false) {
            return preg_replace('/[^a-z]/', '', $slug);
        }
    }

    $map = [
        'ä' => 'a',
        'á' => 'a',
        'à' => 'a',
        'â' => 'a',
        'å' => 'a',
        'ã' => 'a',
        'ë' => 'e',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ï' => 'i',
        'í' => 'i',
        'ì' => 'i',
        'î' => 'i',
        'ö' => 'o',
        'ó' => 'o',
        'ò' => 'o',
        'ô' => 'o',
        'ø' => 'o',
        'õ' => 'o',
        'ü' => 'u',
        'ú' => 'u',
        'ù' => 'u',
        'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
        'ß' => 'ss',
        'Ä' => 'a',
        'Á' => 'a',
        'À' => 'a',
        'Â' => 'a',
        'Å' => 'a',
        'Ë' => 'e',
        'É' => 'e',
        'È' => 'e',
        'Ê' => 'e',
        'Ï' => 'i',
        'Í' => 'i',
        'Ì' => 'i',
        'Î' => 'i',
        'Ö' => 'o',
        'Ó' => 'o',
        'Ò' => 'o',
        'Ô' => 'o',
        'Ø' => 'o',
        'Ü' => 'u',
        'Ú' => 'u',
        'Ù' => 'u',
        'Û' => 'u',
        'Ñ' => 'n',
        'Ç' => 'c',
    ];

    return preg_replace('/[^a-z]/', '', strtolower(strtr($city, $map)));
}

function parseCities(string $html): array
{
    $countryMap = [];
    foreach (range('A', 'Z') as $a) {
        foreach (range('A', 'Z') as $b) {
            $code = $a . $b;
            $name = Locale::getDisplayRegion("und-{$code}", 'en');
            if ($name && $name !== $code) {
                $countryMap[$name] = $code;
            }
        }
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);

    $skip = [
        'All Systems',
        'Last Incident',
        'Past Incidents',
        'Upcoming Maintenance',
        'Current Status',
        'VPN Servers',
    ];
    $seen   = [];
    $cities = [];

    foreach ($xpath->query('//text()') as $node) {
        $text = trim($node->nodeValue);

        if (!preg_match(
            '/^([\p{Lu}][\p{L}\s\-]+),\s+([\p{Lu}][\p{L}\s]+)$/u',
            $text,
            $matches
        )) {
            continue;
        }

        $city    = trim($matches[1]);
        $country = trim($matches[2]);

        if (in_array($city, $skip, true)) continue;

        $key = "{$city}|{$country}";
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $cities[] = [
            'city'    => $city,
            'country' => $country,
            'iso'     => $countryMap[$country] ?? 'XX',
        ];
    }

    usort($cities, fn($a, $b) => strcmp($a['city'], $b['city']));

    return $cities;
}

function ipToPoolDns(string $ip): string
{
    return str_replace('.', '-', $ip) . '.pool.ovpn.com';
}

/**
 * Resolve all VPN server IPs for a city slug using parallel DNS-over-HTTPS
 * (Cloudflare 1.1.1.1). Falls back to native gethostbynamel() if cURL is
 * unavailable. Stops after MAX_CONSECUTIVE_MISSES sequential NXDOMAINs to
 * avoid wasting time on non-existent high-numbered hosts.
 */
function resolveIps(string $slug): array
{
    // Build the full candidate list first, then batch-resolve.
    $hosts  = [];
    for ($i = 1; $i <= MAX_HOST_INDEX; $i++) {
        $hosts[] = sprintf("vpn%02d.prd.%s.ovpn.com", $i, $slug);
    }

    // Prefer parallel DoH via curl_multi; fall back to sequential gethostbynamel.
    if (function_exists('curl_multi_init')) {
        $ips = resolveIpsDoH($hosts);
        if (!empty($ips)) {
            return $ips;
        }
        status("DoH returned no results for slug '{$slug}', falling back to native resolver");
    }

    return resolveIpsNative($hosts);
}

/**
 * Parallel DNS-over-HTTPS using curl_multi (Cloudflare 1.1.1.1 JSON API).
 * Processes hosts in batches and stops early once MAX_CONSECUTIVE_MISSES
 * sequential batches return no new IPs.
 *
 * @param  string[] $hosts
 * @return string[]
 */
function resolveIpsDoH(array $hosts): array
{
    return resolveIpsDoHProvider($hosts, DOH_URL);
}

/**
 * Parallel fallback using Google DoH (dns.google/resolve).
 * Used when Cloudflare DoH returns no results (rate-limited, blocked, etc.).
 * Falls back to sequential gethostbynamel() if curl_multi is unavailable.
 *
 * @param  string[] $hosts
 * @return string[]
 */
function resolveIpsNative(array $hosts): array
{

    // Last-resort sequential fallback (no curl_multi available).
    $ips               = [];
    foreach ($hosts as $host) {
        exec("dig +short $host", $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("dig command failed with exit code $exitCode");
        }
        echo ".";
        $ips = array_values(array_filter($ips));
    }

    echo "\n";
    return $ips;
}

/**
 * Parallel DoH resolver against an arbitrary JSON-API endpoint.
 * Compatible with both Cloudflare (application/dns-json) and Google (dns.google/resolve).
 *
 * @param  string[] $hosts
 * @param  string   $endpoint  Base URL, e.g. 'https://dns.google/resolve'
 * @return string[]
 */
function resolveIpsDoHProvider(array $hosts, string $endpoint): array
{
    $ips               = [];
    $consecutiveMisses = 0;

    foreach (array_chunk($hosts, DOH_BATCH_SIZE) as $batch) {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batch as $host) {
            $ch = curl_init($endpoint . '?' . http_build_query(['name' => $host, 'type' => 'A']));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$host] = $ch;
            //status("DoH ({$endpoint}): $host");
        }

        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        $batchHits = 0;
        foreach ($handles as $host => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === false || $body === '') continue;

            $data = json_decode($body, true);
            if (!isset($data['Answer']) || !is_array($data['Answer'])) continue;

            foreach ($data['Answer'] as $record) {
                if (($record['type'] ?? 0) === 1 && isset($record['data'])) {
                    $ip = $record['data'];
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !in_array($ip, $ips, true)) {
                        $ips[] = $ip;
                        $batchHits++;
                    }
                }
            }
        }

        curl_multi_close($mh);

        if ($batchHits === 0) {
            if (++$consecutiveMisses >= 3) {
                break;
            }
        } else {
            $consecutiveMisses = 0;
        }
    }

    return $ips;
}

function writeOutput(string $json, ?string $outputFile): void
{
    if ($outputFile === null) {
        echo $json . "\n";
        return;
    }

    $dir = dirname($outputFile);
    if ($dir !== '.' && !is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new RuntimeException("Cannot create directory: $dir");
        }
    }

    if (file_put_contents($outputFile, $json . "\n") === false) {
        throw new RuntimeException("Cannot write to file: $outputFile");
    }

    status("Output written to: $outputFile");
}

// Main

$opts = parseArgs($argv);

status("\e[1mOVPN IP Grabber\e[0m");
status("Repository: https://github.com/xtrcode/ovpn-ip-grabber");
status("License: MIT");
status(str_repeat('─', 47));

try {
    $html = fetchPage('https://status.ovpn.com');
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFetch error: {$e->getMessage()}\e[0m\n");
    exit(1);
}

$cities = parseCities($html);

if (empty($cities)) {
    fwrite(STDERR, "\e[31mNo cities found — page structure may have changed.\e[0m\n");
    exit(1);
}

status("\nCities found: " . count($cities));

$results = array_map(function (array $city): array {
    $city['slug'] = cityToSlug($city['city']);
    return $city;
}, $cities);

if ($opts['dig']) {
    status("\n" . str_repeat('─', 60));
    status("\e[1mResolving IPs via dig...\e[0m");
    status(str_repeat('─', 60));

    foreach ($results as &$entry) {
        $slug = $entry['slug'];
        status("Resolving: {$entry['city']}, {$entry['country']} (slug: {$slug})");
        $ips  = resolveIps($slug);

        status($entry['country'] . ", " . $entry['city'] . ": " . count($ips) . " IP(s) found");
        $entry['ips'] = $ips;
        $entry['dns'] = array_map('ipToPoolDns', $ips);
    }
    unset($entry);
}

try {
    writeOutput(
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $opts['output']
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mOutput error: {$e->getMessage()}\e[0m\n");
    exit(1);
}