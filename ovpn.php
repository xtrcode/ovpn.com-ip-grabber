<?php

/**
 * OVPN IP Fetcher
 * Scrapes status.ovpn.com for all VPN cities, then fetches the server list
 * for each city from the status API: /datacenters/{slug}/servers
 *
 * Usage:
 *   php ovpn_cities.php                          # list cities as JSON (stdout)
 *   php ovpn_cities.php --dig                    # fetch servers from API
 *   php ovpn_cities.php --dig --output=out.json  # write JSON to file
 *   php ovpn_cities.php --dig -o out.json        # shorthand
 */

declare(strict_types=1);

// ── CLI argument parsing ──────────────────────────────────────────────────────

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
            if (!isset($args[$i + 1])) {
                fwrite(STDERR, "FATAL: --output / -o requires a filename argument.\n");
                exit(1);
            }
            $opts['output'] = $args[++$i];
        } else {
            fwrite(STDERR, "Warning: Unknown argument ignored: $arg\n");
        }
    }

    return $opts;
}

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
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9', 'Accept: application/json, text/html'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($body === false || $err) {
        throw new RuntimeException("cURL failed (errno {$errno}): {$err} — URL: {$url}");
    }

    if ($code === 404) {
        throw new RuntimeException("HTTP 404 Not Found: {$url}");
    }

    if ($code !== 200) {
        throw new RuntimeException("HTTP {$code} from {$url} ({$time}ms)");
    }

    if (strlen($body) < 10) {
        throw new RuntimeException("Response too small (" . strlen($body) . " bytes) from {$url}");
    }

    return $body;
}

/**
 * DNS slug: strips everything non-alpha.
 * "Los Angeles" → "losangeles", "Malmö" → "malmo"
 */
function cityToDnsSlug(string $city): string
{
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $city);
        if ($s !== false) return preg_replace('/[^a-z]/', '', $s);
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

/**
 * API slug: keeps hyphens between words (different from DNS slug).
 * "Los Angeles" → "los-angeles", "New York" → "new-york", "Malmö" → "malmo"
 */
function cityToApiSlug(string $city): string
{
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $city);
        if ($s !== false) {
            return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        }
    }

    $map = [
        'ä' => 'a',
        'á' => 'a',
        'à' => 'a',
        'â' => 'a',
        'å' => 'a',
        'ö' => 'o',
        'ó' => 'o',
        'ø' => 'o',
        'ü' => 'u',
        'ú' => 'u',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ñ' => 'n',
        'ç' => 'c',
        'ß' => 'ss',
    ];

    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($city, $map))), '-');
}

// ── City parser ───────────────────────────────────────────────────────────────

function parseCities(string $html): array
{
    if (!extension_loaded('intl')) {
        throw new RuntimeException('The intl PHP extension is required (used for country ISO code lookup).');
    }

    // Build reverse map: "Germany" → "DE", "Sweden" → "SE", … via ICU — no hardcoding.
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

        $iso = $countryMap[$country] ?? 'XX';
        if ($iso === 'XX') {
            status("Unknown country ISO for: '{$country}' (city: {$city})");
        }

        $cities[] = [
            'city'     => $city,
            'country'  => $country,
            'iso'      => $iso,
            'slug'     => cityToDnsSlug($city),
            'api_slug' => cityToApiSlug($city),
        ];
    }

    if (empty($cities)) {
        throw new RuntimeException(
            "No cities parsed from HTML — page structure may have changed.\n" .
                "  Check https://status.ovpn.com manually."
        );
    }

    usort($cities, fn($a, $b) => strcmp($a['city'], $b['city']));

    return $cities;
}

function ipToPoolDns(string $ip): string
{
    return str_replace('.', '-', $ip) . '.pool.ovpn.com';
}

/**
 * Fetch the server list for a city from the OVPN status API.
 * Endpoint: https://status.ovpn.com/datacenters/{api_slug}/servers
 * Response: {"data":[{"online":bool,"uptime":string,"bandwidth":int,
 *            "bandwidth_usage":int,"port_speed":int,"name":string,"ip":string}]}
 */
function fetchCityServers(string $apiSlug): array
{
    $url = "https://status.ovpn.com/datacenters/{$apiSlug}/servers";

    try {
        $body = fetchPage($url);
    } catch (RuntimeException $e) {
        status("API error [{$apiSlug}]: " . $e->getMessage());
        return [];
    }

    try {
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        status("Invalid JSON from [{$url}]: " . $e->getMessage());
        return [];
    }

    if (!isset($json['data']) || !is_array($json['data'])) {
        status("Unexpected API structure for [{$apiSlug}] — 'data' key missing or not an array");
        return [];
    }

    return $json['data'];
}

/**
 * Normalize a raw server entry from the API into a clean output structure.
 */
function normalizeServer(array $server): array
{
    $ip = trim($server['ip'] ?? '');

    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        status("Error response: '{$ip}' for server '{$server['name']}' — skipping");
        $ip = '';
    }

    return [
        'name'            => $server['name']            ?? null,
        'ip'              => $ip,
        'pool_dns'        => $ip !== '' ? ipToPoolDns($ip) : null,
        'online'          => (bool)  ($server['online']          ?? false),
        'uptime'          => $server['uptime']          ?? null,
        'bandwidth_mbit'  => (int)   ($server['bandwidth']       ?? 0),
        'bandwidth_usage' => (int)   ($server['bandwidth_usage'] ?? 0),
        'port_speed_mbit' => (int)   ($server['port_speed']      ?? 0),
    ];
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
            $err = error_get_last();
            throw new RuntimeException(
                "Cannot create directory: {$dir}\n" .
                    "  " . ($err['message'] ?? 'Unknown error')
            );
        }
    }

    if (!is_writable($dir)) {
        throw new RuntimeException(
            "Output directory is not writable: {$dir}\n" .
                "  Check permissions for the target path."
        );
    }

    $bytes = file_put_contents($outputFile, $json . "\n");

    if ($bytes === false) {
        $err = error_get_last();
        throw new RuntimeException(
            "Failed to write: {$outputFile}\n" .
                "  " . ($err['message'] ?? 'Unknown error')
        );
    }

    status("Output written to: {$outputFile} ({$bytes} bytes)");
}

// Main

if (!extension_loaded('curl')) {
    fwrite(STDERR, "FATAL: ext-curl is required.\n");
    exit(1);
}
if (!extension_loaded('dom')) {
    fwrite(STDERR, "FATAL: ext-dom is required.\n");
    exit(1);
}
if (!extension_loaded('intl')) {
    fwrite(STDERR, "FATAL: ext-intl is required.\n");
    exit(1);
}

$opts = parseArgs($argv);

status("\e[1mOVPN IP Fetcher\e[0m");
status("Repository: https://github.com/xtrcode/ovpn-ip-grabber");
status("License: MIT");
status(str_repeat('─', 47));

status("\nFetching city list from status.ovpn.com...");

try {
    $html   = fetchPage('https://status.ovpn.com');
    $cities = parseCities($html);
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFATAL: {$e->getMessage()}\e[0m\n");
    exit(1);
}

status("Cities found: " . count($cities));

if (!$opts['dig']) {
    try {
        writeOutput(
            json_encode($cities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $opts['output']
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, "\e[31mOutput error: {$e->getMessage()}\e[0m\n");
        exit(1);
    }
    exit(0);
}

status("\n" . str_repeat('─', 60));
status("\e[1mFetching servers from API...\e[0m");
status(str_repeat('─', 60));

$results   = [];
$totalIps  = 0;
$failed    = [];

foreach ($cities as $entry) {
    $apiSlug = $entry['api_slug'];
    fwrite(STDERR, sprintf("  %-25s (%-20s) ... ", $entry['city'], $apiSlug));

    $raw     = fetchCityServers($apiSlug);
    $servers = array_values(array_filter(
        array_map('normalizeServer', $raw),
        fn($s) => $s['ip'] !== ''          // drop entries with no valid IP
    ));

    $online = count(array_filter($servers, fn($s) => $s['online']));
    $total  = count($servers);

    if ($total === 0) {
        fwrite(STDERR, "\e[33mno servers returned\e[0m\n");
        $failed[] = "{$entry['city']} (slug: {$apiSlug})";
    } else {
        fwrite(STDERR, "\e[32m{$total} server(s), {$online} online\e[0m\n");
        $totalIps += $total;
    }

    $entry['servers'] = $servers;
    $entry['ips']     = array_column($servers, 'ip');
    $entry['dns']     = array_filter(array_column($servers, 'pool_dns'));

    $results[] = $entry;
}

status("\n" . str_repeat('─', 60));
status(sprintf("Cities   : %d", count($results)));
status(sprintf("Servers  : %d", $totalIps));

if (!empty($failed)) {
    status("\n\e[33mNo servers found for:\e[0m");
    foreach ($failed as $f) {
        status("  · {$f}");
    }
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