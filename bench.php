<?php

/**
 * OVPN Bench Tool
 * Reads servers.json produced by ovpn_cities.php and measures latency to every
 * server IP. Uses ICMP ping when available, falls back to TCP connect timing
 * via cURL (not fsockopen) in restricted environments (GitHub Actions, Docker).
 *
 * Usage:
 *   php bench.php                            # ping all servers in servers.json
 *   php bench.php --input=servers.json       # explicit input file
 *   php bench.php --online-only              # skip offline servers
 *   php bench.php --top=10                   # show only the 10 fastest results
 *   php bench.php --output=results.json      # also write results to JSON file
 */

declare(strict_types=1);

// ── CLI args ──────────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $opts = [
        'input'       => 'servers.json',
        'online_only' => false,
        'top'         => null,
        'output'      => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--input='))      $opts['input']       = substr($arg, 8);
        elseif (str_starts_with($arg, '--output=')) $opts['output']      = substr($arg, 9);
        elseif (str_starts_with($arg, '--top='))    $opts['top']         = (int) substr($arg, 6);
        elseif ($arg === '--online-only')            $opts['online_only'] = true;
        else fwrite(STDERR, "Warning: Unknown argument ignored: $arg\n");
    }

    return $opts;
}

// ── Stats helpers ─────────────────────────────────────────────────────────────

function calcStats(array $samples): array
{
    if (empty($samples)) {
        return [
            'min'     => null,
            'max'     => null,
            'avg'    => null,
            'median'  => null,
            'stddev'  => null,
            'samples' => 0,
        ];
    }

    sort($samples);
    $n      = count($samples);
    $avg    = array_sum($samples) / $n;
    $median = $n % 2 === 0
        ? ($samples[$n / 2 - 1] + $samples[$n / 2]) / 2
        : $samples[(int) ($n / 2)];

    $variance = array_sum(array_map(fn($x) => ($x - $avg) ** 2, $samples)) / $n;

    return [
        'min'     => round($samples[0],      2),
        'max'     => round($samples[$n - 1], 2),
        'avg'     => round($avg,             2),
        'median'  => round($median,          2),
        'stddev'  => round(sqrt($variance),  2),
        'samples' => $n,
    ];
}

// ── Ping binary detection ─────────────────────────────────────────────────────

function findPingBinary(): string
{
    foreach (['which ping', 'where ping'] as $cmd) {
        $out = [];
        $status = 0;
        exec($cmd . ' 2>/dev/null', $out, $status);
        if ($status === 0 && !empty($out[0])) {
            $path = trim($out[0]);
            if (is_executable($path)) return $path;
        }
    }

    foreach (['/bin/ping', '/sbin/ping', '/usr/bin/ping', '/usr/sbin/ping', 'C:\\Windows\\System32\\ping.exe'] as $path) {
        if (is_executable($path)) return $path;
    }

    throw new RuntimeException(
        "ping binary not found.\n" .
            "  Debian/Ubuntu : sudo apt-get install iputils-ping\n" .
            "  RHEL/Fedora   : sudo dnf install iputils\n" .
            "  Alpine        : apk add iputils"
    );
}

/**
 * Probe whether ICMP actually works in this environment by pinging 1.1.1.1.
 * GitHub Actions blocks raw ICMP sockets — errno=0/false from fsockopen is
 * the same class of restriction. Falls back to cURL TCP timing.
 * Returns 'icmp' or 'tcp'.
 */
function detectPingMethod(string $pingBin): string
{
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $cmd = $isWindows
        ? sprintf('%s -n 1 -w 2000 1.1.1.1 2>&1', escapeshellarg($pingBin))
        : sprintf('%s -c 1 -W 2 1.1.1.1 2>&1',    escapeshellarg($pingBin));

    $out = [];
    exec($cmd, $out, $status);

    if ($status === 0 && preg_match('/time[<=][0-9.]+\s*ms/i', implode("\n", $out))) {
        fwrite(STDERR, "Ping method : ICMP ({$pingBin})\n");
        return 'icmp';
    }

    fwrite(STDERR, "Ping method : TCP/cURL (ICMP blocked — GitHub Actions / restricted env)\n");
    return 'tcp';
}

// ── Latency measurement ───────────────────────────────────────────────────────

function pingHost(string $ip, string $pingBin, string $method, int $probes): array
{
    return $method === 'icmp' ? pingIcmp($ip, $pingBin, $probes) : pingTcp($ip, 5.0, $probes);
}

function pingIcmp(string $ip, string $pingBin, int $probes = 5): array
{
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $samples   = [];

    for ($i = 0; $i < $probes; $i++) {
        $cmd = $isWindows
            ? sprintf('%s -n %d -w 2000 %s 2>&1', escapeshellarg($pingBin), $probes, escapeshellarg($ip))
            : sprintf('%s -c %d -W 2 %s 2>&1',    escapeshellarg($pingBin), $probes, escapeshellarg($ip));

        $out = [];
        exec($cmd, $out, $status);
        if ($status !== 0) continue;

        $text = implode("\n", $out);
        if (
            preg_match('/[Zz]eit[<=](\d+)ms/i',    $text, $m) ||
            preg_match('/time[<=]([0-9.]+)\s*ms/i', $text, $m)
        ) {
            $samples[] = (float) $m[1];
        }
    }

    return calcStats($samples);
}

/**
 * Measure TCP connect latency using cURL.
 *
 * Why cURL instead of fsockopen:
 *   fsockopen() with errno=0 / false return means the socket failed to
 *   initialise before connect() was even called — a kernel/capability
 *   restriction present in GitHub Actions and hardened containers.
 *   cURL uses its own socket layer and is not subject to the same restriction.
 *
 * Ports tried in order: 443 → 80 → 1194.
 * CURLINFO_CONNECT_TIME_T returns microseconds for the completed TCP handshake.
 * A refused connection (CURLE_COULDNT_CONNECT / errno 7) still yields timing
 * data in some curl builds; a silent timeout (errno 28) skips to the next port.
 */
function pingTcp(string $ip, float $timeout = 5.0, int $probes = 10): array
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "  ✗ cURL extension not available — cannot measure TCP latency\n");
        return calcStats([]);
    }

    $samples  = [];
    $ports    = [443, 80, 1194];
    $goodPort = null;

    // Port discovery pass: find the first port that completes a TCP handshake
    foreach ($ports as $port) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => "http://{$ip}:{$port}/",
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($timeout * 1000),
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOBODY            => true,
            CURLOPT_FRESH_CONNECT     => true,
            CURLOPT_FORBID_REUSE      => true,
        ]);

        curl_exec($ch);
        $connectUs = curl_getinfo($ch, CURLINFO_CONNECT_TIME_T); // microseconds
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // CONNECT_TIME_T > 0 means the TCP handshake completed
        if ($connectUs > 0) {
            $goodPort  = $port;
            $samples[] = round($connectUs / 1000, 2); // µs → ms
            break;
        }

        // errno 28 = CURLE_OPERATION_TIMEDOUT — silent drop, try next port
        // errno 7  = CURLE_COULDNT_CONNECT   — refused (host reachable but port closed)
        // Any other errno: try next port
    }

    if ($goodPort === null) {
        return calcStats([]); // All ports unreachable
    }

    // Measurement pass: repeat probes on the confirmed working port
    for ($i = 1; $i < $probes; $i++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => "http://{$ip}:{$goodPort}/",
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($timeout * 1000),
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOBODY            => true,
            CURLOPT_FRESH_CONNECT     => true,
            CURLOPT_FORBID_REUSE      => true,
        ]);

        curl_exec($ch);
        $connectUs = curl_getinfo($ch, CURLINFO_CONNECT_TIME_T);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($connectUs > 0) {
            $samples[] = round($connectUs / 1000, 2);
        } elseif ($curlErrno === 28) {
            // Intermittent timeout — count as a dropped probe, don't add sample
            fwrite(STDERR, " [probe timeout]");
        }
    }

    return calcStats($samples);
}

// ── Load servers.json ─────────────────────────────────────────────────────────

function loadServers(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Input file not found: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException("Cannot read: {$path}");

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage());
    }

    if (!is_array($data)) throw new RuntimeException("Expected a JSON array in {$path}");

    return $data;
}

// ── Flatten city list → individual server rows ────────────────────────────────

function flattenServers(array $cities, bool $onlineOnly): array
{
    $rows = [];

    foreach ($cities as $city) {
        $cityName = $city['city']    ?? 'Unknown';
        $country  = $city['country'] ?? 'Unknown';
        $iso      = $city['iso']     ?? 'XX';
        $servers  = $city['servers'] ?? [];

        if (empty($servers)) {
            fwrite(STDERR, "  ⚠ No servers for {$cityName}, {$country} — skipping\n");
            continue;
        }

        foreach ($servers as $server) {
            $online = (bool) ($server['online'] ?? false);
            if ($onlineOnly && !$online) continue;

            $ip = trim($server['ip'] ?? '');
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                fwrite(STDERR, "  ⚠ Invalid IP for {$server['name']} ({$cityName}) — skipping\n");
                continue;
            }

            $rows[] = [
                'name'            => $server['name']            ?? '?',
                'city'            => $cityName,
                'country'         => $country,
                'iso'             => $iso,
                'ip'              => $ip,
                'pool_dns'        => $server['pool_dns']        ?? null,
                'online'          => $online,
                'uptime'          => $server['uptime']          ?? null,
                'bandwidth_mbit'  => $server['bandwidth_mbit']  ?? null,
                'bandwidth_usage' => $server['bandwidth_usage'] ?? null,
                'port_speed_mbit' => $server['port_speed_mbit'] ?? null,
                'ping_avg'        => null,
                'ping_median'     => null,
                'ping_stddev'     => null,
                'ping_min'        => null,
                'ping_max'        => null,
                'ping_ms'         => null, // sort key (= avg)
                'ping_method'     => null,
            ];
        }
    }

    return $rows;
}

// ── Table printer ─────────────────────────────────────────────────────────────

function printTable(array $rows, string $pingMethod): void
{
    $label = $pingMethod === 'icmp' ? 'ICMP' : 'TCP';
    $fmt   = "%-6s  %-7s  %-16s  %-15s  %9s  %8s  %7s  %9s  %5s%%\n";

    printf($fmt, 'Rank', 'Server', 'IP', 'City', "avg({$label})", 'median', 'σ', 'BW(Mbit)', 'Load');
    echo str_repeat('─', 100) . "\n";

    foreach ($rows as $rank => $r) {
        $f = fn($v) => $v !== null ? sprintf('%.2f', $v) : 'timeout';

        printf(
            $fmt,
            '#' . ($rank + 1),
            $r['name'],
            $r['ip'],
            $r['city'],
            $f($r['ping_avg']),
            $f($r['ping_median']),
            $f($r['ping_stddev']),
            $r['bandwidth_mbit']  ?? '-',
            $r['bandwidth_usage'] ?? '-'
        );
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);

fwrite(STDERR, "\e[1mOVPN Bench Tool\e[0m\n");
fwrite(STDERR, str_repeat('─', 47) . "\n");

// Locate ping binary
try {
    $pingBin = findPingBinary();
    fwrite(STDERR, "Using ping  : {$pingBin}\n");
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFATAL: {$e->getMessage()}\e[0m\n");
    exit(1);
}

$pingMethod = detectPingMethod($pingBin);

// Load input
try {
    $cities = loadServers($opts['input']);
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFATAL: {$e->getMessage()}\e[0m\n");
    exit(1);
}

$rows = flattenServers($cities, $opts['online_only']);

if (empty($rows)) {
    fwrite(STDERR, "\e[31mNo servers to ping after filtering.\e[0m\n");
    exit(1);
}

fwrite(STDERR, sprintf(
    "Loaded %d server(s) across %d city/cities from %s\n\n",
    count($rows),
    count($cities),
    $opts['input']
));

fwrite(STDERR, "Pinging servers...\n");

$total   = count($rows);
$timeout = 0;
const probes = 3;

foreach ($rows as $i => &$row) {
    fwrite(STDERR, sprintf(
        "  [%d/%d] %-7s %-16s %-20s ...",
        $i + 1,
        $total,
        $row['name'],
        $row['ip'],
        $row['city']
    ));


    $stats = pingHost($row['ip'], $pingBin, $pingMethod, probes);

    $row['ping_avg']    = $stats['avg'];
    $row['ping_median'] = $stats['median'];
    $row['ping_stddev'] = $stats['stddev'];
    $row['ping_min']    = $stats['min'];
    $row['ping_max']    = $stats['max'];
    $row['ping_ms']     = $stats['avg']; // sort key
    $row['ping_method'] = $pingMethod;

    if ($stats['avg'] === null) {
        $timeout++;
        fwrite(STDERR, " \e[33mtimeout (0/{$stats['samples']} probes)\e[0m\n");
    } else {
        fwrite(STDERR, sprintf(
            " \e[32mavg=%.2fms  med=%.2fms  σ=%.2fms  (%d/%d probes)\e[0m\n",
            $stats['avg'],
            $stats['median'],
            $stats['stddev'],
            $stats['samples'],
            probes
        ));
    }
}
unset($row);

fwrite(STDERR, "\n");

// Sort by avg ping ascending, timeouts last
usort($rows, function ($a, $b) {
    if ($a['ping_ms'] === null && $b['ping_ms'] === null) return 0;
    if ($a['ping_ms'] === null) return 1;
    if ($b['ping_ms'] === null) return -1;
    return $a['ping_ms'] <=> $b['ping_ms'];
});

if ($opts['top'] !== null && $opts['top'] > 0) {
    $rows = array_slice($rows, 0, $opts['top']);
}

$reachable = count(array_filter($rows, fn($r) => $r['ping_ms'] !== null));
echo sprintf("\nResults: %d reachable, %d timeout(s) [method: %s]\n\n", $reachable, $timeout, $pingMethod);
printTable($rows, $pingMethod);

if ($opts['output'] !== null) {
    $dir = dirname($opts['output']);
    if ($dir !== '.' && !is_dir($dir)) mkdir($dir, 0755, true);
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($opts['output'], $json . "\n") !== false) {
        fwrite(STDERR, "\nResults written to: {$opts['output']}\n");
    } else {
        fwrite(STDERR, "\e[31mFailed to write: {$opts['output']}\e[0m\n");
    }
}