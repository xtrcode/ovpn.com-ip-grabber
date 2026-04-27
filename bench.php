<?php

/**
 * OVPN Ping / Bench Tool
 * Reads servers.json produced by ovpn_cities.php and measures latency to every
 * server IP. Uses ICMP ping when available, falls back to TCP connect timing
 * in restricted environments (GitHub Actions, Docker, etc.).
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

    $candidates = [
        '/bin/ping',
        '/sbin/ping',
        '/usr/bin/ping',
        '/usr/sbin/ping',
        'C:\\Windows\\System32\\ping.exe',
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) return $path;
    }

    throw new RuntimeException(
        "ping binary not found on this system.\n" .
            "  Install it with:\n" .
            "    Debian/Ubuntu : sudo apt-get install iputils-ping\n" .
            "    RHEL/Fedora   : sudo dnf install iputils\n" .
            "    Alpine        : apk add iputils\n" .
            "    macOS         : ping is built-in — check your PATH"
    );
}

/**
 * Probe whether ICMP actually works in this environment.
 * GitHub Actions and most CI runners block raw ICMP sockets silently.
 * Returns 'icmp' if functional, 'tcp' if ICMP is blocked.
 */
function detectPingMethod(string $pingBin): string
{
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $testIp    = '1.1.1.1';

    $cmd = $isWindows
        ? sprintf('%s -n 1 -w 2000 %s 2>&1', escapeshellarg($pingBin), escapeshellarg($testIp))
        : sprintf('%s -c 1 -W 2 %s 2>&1',    escapeshellarg($pingBin), escapeshellarg($testIp));

    $out = [];
    $status = 0;
    exec($cmd, $out, $status);
    $text = implode("\n", $out);

    if ($status === 0 && preg_match('/time[<=][0-9.]+\s*ms/i', $text)) {
        fwrite(STDERR, "Ping method : ICMP ({$pingBin})\n");
        return 'icmp';
    }

    fwrite(STDERR, "Ping method : TCP connect (ICMP blocked — likely CI/GitHub Actions)\n");
    return 'tcp';
}

// ── Latency measurement ───────────────────────────────────────────────────────

function pingHost(string $ip, string $pingBin, string $method): float
{
    return $method === 'icmp' ? pingIcmp($ip, $pingBin) : pingTcp($ip);
}

function pingIcmp(string $ip, string $pingBin): float
{
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $cmd = $isWindows
        ? sprintf('%s -n 1 -w 2000 %s 2>&1', escapeshellarg($pingBin), escapeshellarg($ip))
        : sprintf('%s -c 1 -W 2 %s 2>&1',    escapeshellarg($pingBin), escapeshellarg($ip));

    $output = [];
    exec($cmd, $output, $status);

    if ($status !== 0) return PHP_INT_MAX;

    $text = implode("\n", $output);
    if (
        preg_match('/[Zz]eit[<=](\d+)ms/i',    $text, $m) ||
        preg_match('/time[<=]([0-9.]+)\s*ms/i', $text, $m)
    ) {
        return (float) $m[1];
    }

    return PHP_INT_MAX;
}

/**
 * Measure TCP connect time on port 443, falling back to 1194 (OpenVPN default).
 * Works in environments where ICMP is blocked (GitHub Actions, Docker, etc.).
 */
function pingTcp(string $ip, float $timeout = 3.0): float
{
    foreach ([443, 1194] as $port) {
        $start  = microtime(true);
        $socket = @fsockopen('tcp://' . $ip, $port, $errno, $errstr, $timeout);

        if ($socket !== false) {
            $ms = (microtime(true) - $start) * 1000;
            fclose($socket);
            return round($ms, 2);
        }
    }

    return PHP_INT_MAX;
}

// ── Load servers.json ─────────────────────────────────────────────────────────

function loadServers(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Input file not found: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read file: {$path}");
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage());
    }

    if (!is_array($data)) {
        throw new RuntimeException("Expected a JSON array in {$path}");
    }

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
                fwrite(STDERR, "  ⚠ Invalid/missing IP for {$server['name']} ({$cityName}) — skipping\n");
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
                'ping_ms'         => null,
                'ping_method'     => null,
            ];
        }
    }

    return $rows;
}

// ── Table printer ─────────────────────────────────────────────────────────────

function printTable(array $rows, string $pingMethod): void
{
    $pingLabel = $pingMethod === 'icmp' ? 'ICMP(ms)' : 'TCP(ms)';
    $fmt = "%-6s  %-7s  %-16s  %-15s  %-22s  %9s  %9s  %5s%%\n";

    printf($fmt, 'Rank', 'Server', 'IP', 'City', 'Country', $pingLabel, 'BW(Mbit)', 'Load');
    echo str_repeat('─', 105) . "\n";

    foreach ($rows as $rank => $r) {
        $ping = ($r['ping_ms'] === null || $r['ping_ms'] >= PHP_INT_MAX)
            ? 'timeout'
            : sprintf('%.2f', $r['ping_ms']);

        $bw   = $r['bandwidth_mbit']  !== null ? (string) $r['bandwidth_mbit']  : '-';
        $load = $r['bandwidth_usage'] !== null ? (string) $r['bandwidth_usage'] : '-';

        printf($fmt, '#' . ($rank + 1), $r['name'], $r['ip'], $r['city'], $r['country'], $ping, $bw, $load);
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);

fwrite(STDERR, "\e[1mOVPN Ping Tool\e[0m\n");
fwrite(STDERR, str_repeat('─', 47) . "\n");

// Locate ping binary
try {
    $pingBin = findPingBinary();
    fwrite(STDERR, "Using ping  : {$pingBin}\n");
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFATAL: {$e->getMessage()}\e[0m\n");
    exit(1);
}

// Detect if ICMP is usable (blocked in CI)
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

foreach ($rows as $i => &$row) {
    fwrite(STDERR, sprintf(
        "  [%d/%d] %-7s %-16s %-20s ...",
        $i + 1,
        $total,
        $row['name'],
        $row['ip'],
        $row['city']
    ));

    $ping = pingHost($row['ip'], $pingBin, $pingMethod);
    $row['ping_ms']     = $ping;
    $row['ping_method'] = $pingMethod;

    if ($ping >= PHP_INT_MAX) {
        $timeout++;
        fwrite(STDERR, sprintf(" \e[33mtimeout\e[0m\n"));
    } else {
        fwrite(STDERR, sprintf(" \e[32m%.2f ms\e[0m\n", $ping));
    }
}
unset($row);

fwrite(STDERR, "\n");

// Sort by ping ascending (timeouts last)
usort($rows, fn($a, $b) => $a['ping_ms'] <=> $b['ping_ms']);

// Apply --top filter
if ($opts['top'] !== null && $opts['top'] > 0) {
    $rows = array_slice($rows, 0, $opts['top']);
}

$reachable = count(array_filter($rows, fn($r) => $r['ping_ms'] < PHP_INT_MAX));
echo sprintf("\nResults: %d reachable, %d timeout(s) [method: %s]\n\n", $reachable, $timeout, $pingMethod);
printTable($rows, $pingMethod);

// Optional JSON output
if ($opts['output'] !== null) {
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $dir  = dirname($opts['output']);

    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_put_contents($opts['output'], $json . "\n") !== false) {
        fwrite(STDERR, "\nResults written to: {$opts['output']}\n");
    } else {
        fwrite(STDERR, "\e[31mFailed to write: {$opts['output']}\e[0m\n");
    }
}