<?php

/**
 * OVPN Ping Tool
 * Reads servers.json produced by ovpn_cities.php and pings every server IP.
 * Results are sorted by ping time and printed as a table.
 *
 * Usage:
 *   php ping.php                          # ping all servers in results/servers.json
 *   php ping.php --input=results/servers.json     # explicit input file
 *   php ping.php --online-only            # skip offline servers
 *   php ping.php --top=10                 # show only the 10 fastest results
 *   php ping.php --output=results.json    # also write results to JSON file
 */

declare(strict_types=1);

// ── CLI args ──────────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $opts = [
        'input'       => 'results/servers.json',
        'online_only' => false,
        'top'         => null,
        'output'      => null,
    ];

    foreach (array_slice($argv, 1) as $i => $arg) {
        if (str_starts_with($arg, '--input='))       $opts['input']       = substr($arg, 8);
        elseif (str_starts_with($arg, '--output='))  $opts['output']      = substr($arg, 9);
        elseif (str_starts_with($arg, '--top='))     $opts['top']         = (int) substr($arg, 6);
        elseif ($arg === '--online-only')             $opts['online_only'] = true;
        else fwrite(STDERR, "Warning: Unknown argument ignored: $arg\n");
    }

    return $opts;
}

// ── Ping ──────────────────────────────────────────────────────────────────────

function pingHost(string $ip): float
{
    $cmd    = sprintf('ping -c 1 -W 2 %s 2>&1', escapeshellarg($ip));
    $output = [];
    exec($cmd, $output, $status);

    if ($status !== 0) {
        return PHP_INT_MAX;
    }

    $text = implode("\n", $output);
    if (preg_match('/time=([0-9.]+)\s*ms/', $text, $m)) {
        return (float) $m[1];
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
        $cityName  = $city['city']    ?? 'Unknown';
        $country   = $city['country'] ?? 'Unknown';
        $iso       = $city['iso']     ?? 'XX';
        $servers   = $city['servers'] ?? [];

        if (empty($servers)) {
            fwrite(STDERR, "  ⚠ No servers for {$cityName}, {$country} — skipping\n");
            continue;
        }

        foreach ($servers as $server) {
            $online = (bool) ($server['online'] ?? false);

            if ($onlineOnly && !$online) {
                continue;
            }

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
            ];
        }
    }

    return $rows;
}

// ── Table printer ─────────────────────────────────────────────────────────────

function printTable(array $rows): void
{
    $fmt = "%-6s  %-7s  %-16s  %-15s  %-22s  %8s  %9s  %5s%%\n";

    printf(
        $fmt,
        'Rank',
        'Server',
        'IP',
        'City',
        'Country',
        'Ping(ms)',
        'BW(Mbit)',
        'Load'
    );
    echo str_repeat('─', 100) . "\n";

    foreach ($rows as $rank => $r) {
        $ping = $r['ping_ms'] === PHP_INT_MAX || $r['ping_ms'] === null
            ? 'timeout'
            : sprintf('%.2f', $r['ping_ms']);

        $bw   = $r['bandwidth_mbit']  !== null ? (string) $r['bandwidth_mbit']  : '-';
        $load = $r['bandwidth_usage'] !== null ? (string) $r['bandwidth_usage'] : '-';

        printf(
            $fmt,
            '#' . ($rank + 1),
            $r['name'],
            $r['ip'],
            $r['city'],
            $r['country'],
            $ping,
            $bw,
            $load
        );
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);

fwrite(STDERR, "\e[1mOVPN Ping Tool\e[0m\n");
fwrite(STDERR, str_repeat('─', 47) . "\n");

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
        "\r  [%d/%d] %-7s %-16s %-20s ...",
        $i + 1,
        $total,
        $row['name'],
        $row['ip'],
        $row['city']
    ));

    $ping = pingHost($row['ip']);
    $row['ping_ms'] = $ping;

    if ($ping === (float) PHP_INT_MAX) {
        $timeout++;
        fwrite(STDERR, sprintf(
            "\r  [%d/%d] %-7s %-16s %-20s \e[33mtimeout\e[0m       \n",
            $i + 1,
            $total,
            $row['name'],
            $row['ip'],
            $row['city']
        ));
    } else {
        fwrite(STDERR, sprintf(
            "\r  [%d/%d] %-7s %-16s %-20s \e[32m%.2f ms\e[0m       \n",
            $i + 1,
            $total,
            $row['name'],
            $row['ip'],
            $row['city'],
            $ping
        ));
    }
}
unset($row);

fwrite(STDERR, "\n");

// Sort by ping ascending (timeouts go last)
usort($rows, fn($a, $b) => $a['ping_ms'] <=> $b['ping_ms']);

// Apply --top filter
if ($opts['top'] !== null && $opts['top'] > 0) {
    $rows = array_slice($rows, 0, $opts['top']);
}

// Print results table to stdout
$reachable = count(array_filter($rows, fn($r) => $r['ping_ms'] !== (float) PHP_INT_MAX));
echo sprintf("\nResults: %d server(s) reachable, %d timeout(s)\n\n", $reachable, $timeout);
printTable($rows);

// Optional JSON output
if ($opts['output'] !== null) {
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($opts['output'], $json . "\n") !== false) {
        fwrite(STDERR, "\nResults written to: {$opts['output']}\n");
    } else {
        fwrite(STDERR, "\e[31mFailed to write: {$opts['output']}\e[0m\n");
    }
}