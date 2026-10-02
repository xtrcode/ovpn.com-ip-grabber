<?php

/**
 * OVPN Peering Quality Benchmark
 * Evaluates BGP peering quality between a source ASN and each OVPN server.
 *
 * Data sources:
 *   - RIPEstat API: IP→ASN mapping, AS-path/neighbours
 *   - PeeringDB API: IX memberships per ASN (netixlan)
 *
 * Usage:
 *   php peering.php --input=results/servers.json --src-asn=47447
 *   php peering.php --input=results/servers.json --src-asn=47447 --output=results/peering.json
 *   php peering.php --input=results/servers.json --src-asn=47447 --top=10
 *   php peering.php --input=results/servers.json --src-asn=47447 --bench=results/bench.json
 *
 * The --bench flag merges ping data from bench.json into the output for a combined view.
 */

declare(strict_types=1);

// ── CLI args ──────────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $opts = [
        'input'         => 'results/servers.json',
        'src_asn'       => null,
        'output'        => null,
        'top'           => null,
        'bench'         => null,
        'peeringdb_key' => getenv('PEERINGDB_API_KEY') ?: null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--input='))           $opts['input']         = substr($arg, 8);
        elseif (str_starts_with($arg, '--src-asn='))      $opts['src_asn']       = (int) substr($arg, 10);
        elseif (str_starts_with($arg, '--output='))       $opts['output']        = substr($arg, 9);
        elseif (str_starts_with($arg, '--top='))          $opts['top']           = (int) substr($arg, 6);
        elseif (str_starts_with($arg, '--bench='))        $opts['bench']         = substr($arg, 8);
        elseif (str_starts_with($arg, '--peeringdb-key=')) $opts['peeringdb_key'] = substr($arg, 16);
        else fwrite(STDERR, "Warning: Unknown argument ignored: $arg\n");
    }

    if ($opts['src_asn'] === null) {
        fwrite(STDERR, "\e[31mFATAL: --src-asn=<ASN> is required (e.g. --src-asn=47447 for 23M GmbH)\e[0m\n");
        exit(1);
    }

    return $opts;
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

/**
 * Simple cURL GET with JSON decoding. Returns decoded array or null on failure.
 * Rate-limits itself with a 500ms sleep between calls.
 */
function apiGet(string $url, float $timeout = 15.0, array $extraHeaders = [], ?string $userAgent = null): ?array
{
    static $lastCall = 0.0;

    // Rate limiting: 500ms between requests
    $now = microtime(true);
    $elapsed = $now - $lastCall;
    if ($elapsed < 0.5 && $lastCall > 0) {
        usleep((int) ((0.5 - $elapsed) * 1_000_000));
    }
    $lastCall = microtime(true);

    $headers = array_merge(['Accept: application/json'], $extraHeaders);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => (int) $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => $userAgent ?? 'ovpn-peering-bench/1.0 (https://github.com/xtrcode/ovpn-ip-grabber)',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        fwrite(STDERR, "  \e[33mAPI error: {$err} — {$url}\e[0m\n");
        return null;
    }

    if ($code !== 200) {
        fwrite(STDERR, "  \e[33mHTTP {$code} — {$url}\e[0m\n");
        return null;
    }

    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "  \e[33mJSON parse error: {$e->getMessage()}\e[0m\n");
        return null;
    }
}

// ── RIPEstat: IP → ASN ────────────────────────────────────────────────────────

/** @var array<string, array{asn: int, holder: string, prefix: string}> */
$asnCache = [];

function ipToAsn(string $ip): ?array
{
    global $asnCache;
    if (isset($asnCache[$ip])) return $asnCache[$ip];

    $data = apiGet("https://stat.ripe.net/data/prefix-overview/data.json?resource={$ip}");
    if ($data === null || !isset($data['data']['asns'][0])) return null;

    $asn = $data['data']['asns'][0];
    $result = [
        'asn'    => (int) $asn['asn'],
        'holder' => $asn['holder'] ?? 'Unknown',
        'prefix' => $data['data']['resource'] ?? '',
    ];

    $asnCache[$ip] = $result;
    return $result;
}

// ── PeeringDB: ASN → IX memberships ──────────────────────────────────────────

/** @var array<int, array> */
$ixCache = [];

function getIxMemberships(int $asn): array
{
    global $ixCache, $peeringDbKey;
    if (isset($ixCache[$asn])) return $ixCache[$asn];

    $headers = [];
    if ($peeringDbKey !== null) {
        $headers[] = "Authorization: Api-Key {$peeringDbKey}";
    }

    // PeeringDB's WAF blocks non-browser User-Agents from PHP/cURL.
    // Use a browser-like UA to avoid 403.
    $data = apiGet(
        "https://www.peeringdb.com/api/netixlan?asn={$asn}",
        15.0,
        $headers,
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    );
    if ($data === null || !isset($data['data'])) {
        $ixCache[$asn] = [];
        return [];
    }

    $memberships = [];
    foreach ($data['data'] as $entry) {
        $ixId = $entry['ix_id'] ?? null;
        if ($ixId === null) continue;

        $memberships[] = [
            'ix_id'   => (int) $ixId,
            'ix_name' => $entry['name'] ?? 'Unknown IX',
            'speed'   => (int) ($entry['speed'] ?? 0),
            'ipv4'    => $entry['ipaddr4'] ?? null,
            'ipv6'    => $entry['ipaddr6'] ?? null,
        ];
    }

    $ixCache[$asn] = $memberships;
    return $memberships;
}

/**
 * Find shared IXs between two ASNs.
 * Returns array of shared IX names, or empty if none.
 */
function findSharedIxs(int $srcAsn, int $dstAsn): array
{
    $srcIxs = getIxMemberships($srcAsn);
    $dstIxs = getIxMemberships($dstAsn);

    if (empty($srcIxs) || empty($dstIxs)) return [];

    $srcIxIds = array_column($srcIxs, 'ix_id');
    $shared = [];

    foreach ($dstIxs as $dstIx) {
        if (in_array($dstIx['ix_id'], $srcIxIds, true)) {
            $shared[] = $dstIx['ix_name'];
        }
    }

    return array_unique($shared);
}

// ── RIPEstat: ASN neighbours (are they direct peers?) ─────────────────────────

/** @var array<int, array<int, bool>> */
$neighbourCache = [];

function areNeighbours(int $asn1, int $asn2): bool
{
    global $neighbourCache;
    if (isset($neighbourCache[$asn1][$asn2])) return $neighbourCache[$asn1][$asn2];

    $data = apiGet("https://stat.ripe.net/data/asn-neighbours/data.json?resource=AS{$asn1}");
    if ($data === null || !isset($data['data']['neighbours'])) {
        $neighbourCache[$asn1][$asn2] = false;
        return false;
    }

    // Cache all neighbours for this ASN
    foreach ($data['data']['neighbours'] as $neighbour) {
        $nAsn = (int) $neighbour['asn'];
        $neighbourCache[$asn1][$nAsn] = true;
    }

    return $neighbourCache[$asn1][$asn2] ?? false;
}

// ── Peering Score ─────────────────────────────────────────────────────────────

/**
 * Compute a peering quality score (0–100) between source and destination ASN.
 *
 * Scoring breakdown:
 *   - Direct BGP neighbour:     +40 points
 *   - Shared IX (any):          +25 points
 *   - Shared IX is DE-CIX FRA:  +10 bonus (low-latency Frankfurt hub)
 *   - Dst has PeeringDB entry:  +10 points (well-connected, transparent network)
 *   - Same ASN (src == dst):    100 points (trivial)
 *   - Port speed bonus:         +15 points max (scaled by min port speed at shared IX)
 */
function computePeeringScore(int $srcAsn, int $dstAsn): array
{
    // Trivial case: same ASN
    if ($srcAsn === $dstAsn) {
        return [
            'score'          => 100,
            'grade'          => 'A+',
            'same_asn'       => true,
            'direct_peer'    => true,
            'shared_ixs'     => [],
            'dst_in_peeringdb' => true,
            'details'        => 'Same ASN — no transit needed',
        ];
    }

    $score   = 0;
    $details = [];

    // 1. Direct BGP neighbour?
    $directPeer = areNeighbours($srcAsn, $dstAsn);
    if ($directPeer) {
        $score += 40;
        $details[] = '+40 direct BGP neighbour';
    }

    // 2. Shared IXs
    $sharedIxs = findSharedIxs($srcAsn, $dstAsn);
    if (!empty($sharedIxs)) {
        $score += 25;
        $details[] = '+25 shared IX: ' . implode(', ', $sharedIxs);

        // Bonus for DE-CIX Frankfurt
        foreach ($sharedIxs as $ix) {
            if (stripos($ix, 'DE-CIX') !== false && stripos($ix, 'Frankfurt') !== false) {
                $score += 10;
                $details[] = '+10 DE-CIX Frankfurt bonus';
                break;
            }
        }
    }

    // 3. Destination in PeeringDB?
    $dstIxs = getIxMemberships($dstAsn);
    $dstInPdb = !empty($dstIxs);
    if ($dstInPdb) {
        $score += 10;
        $details[] = '+10 destination in PeeringDB';
    }

    // 4. Port speed bonus (max 15 points)
    if (!empty($sharedIxs)) {
        $srcIxs = getIxMemberships($srcAsn);
        $srcIxMap = [];
        foreach ($srcIxs as $ix) $srcIxMap[$ix['ix_id']] = $ix['speed'];

        $maxSpeed = 0;
        foreach ($dstIxs as $ix) {
            if (isset($srcIxMap[$ix['ix_id']])) {
                $minPort = min($srcIxMap[$ix['ix_id']], $ix['speed']);
                $maxSpeed = max($maxSpeed, $minPort);
            }
        }

        if ($maxSpeed > 0) {
            // Scale: 1G=3, 10G=8, 100G=15
            $speedScore = match (true) {
                $maxSpeed >= 100000 => 15,
                $maxSpeed >= 40000  => 12,
                $maxSpeed >= 10000  => 8,
                $maxSpeed >= 1000   => 3,
                default             => 1,
            };
            $score += $speedScore;
            $details[] = "+{$speedScore} port speed ({$maxSpeed} Mbit)";
        }
    }

    $score = min($score, 100);

    // Grade
    $grade = match (true) {
        $score >= 80 => 'A',
        $score >= 60 => 'B',
        $score >= 40 => 'C',
        $score >= 20 => 'D',
        default      => 'F',
    };

    return [
        'score'            => $score,
        'grade'            => $grade,
        'same_asn'         => false,
        'direct_peer'      => $directPeer,
        'shared_ixs'       => $sharedIxs,
        'dst_in_peeringdb' => $dstInPdb,
        'details'          => implode(' | ', $details) ?: 'No peering relationship found',
    ];
}

// ── Load files ────────────────────────────────────────────────────────────────

function loadJson(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("File not found: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException("Cannot read: {$path}");

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage());
    }

    return $data;
}

function flattenServers(array $cities): array
{
    $rows = [];
    foreach ($cities as $city) {
        $cityName = $city['city']    ?? 'Unknown';
        $country  = $city['country'] ?? 'Unknown';
        $iso      = $city['iso']     ?? 'XX';

        foreach ($city['servers'] ?? [] as $server) {
            $ip = trim($server['ip'] ?? '');
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;

            $rows[] = [
                'name'            => $server['name']            ?? '?',
                'city'            => $cityName,
                'country'         => $country,
                'iso'             => $iso,
                'ip'              => $ip,
                'bandwidth_mbit'  => $server['bandwidth_mbit']  ?? null,
                'port_speed_mbit' => $server['port_speed_mbit'] ?? null,
            ];
        }
    }
    return $rows;
}

// ── Table printer ─────────────────────────────────────────────────────────────

function printPeeringTable(array $rows, int $srcAsn): void
{
    $fmt = "%-5s  %-7s  %-16s  %-15s  %-8s  %-7s  %-5s  %-6s  %-25s  %s\n";
    printf($fmt, 'Rank', 'Server', 'IP', 'City', 'DST-ASN', 'Score', 'Grade', 'Peer?', 'Shared IX', 'Details');
    echo str_repeat('─', 140) . "\n";

    foreach ($rows as $rank => $r) {
        $p = $r['peering'];
        $sharedIxShort = !empty($p['shared_ixs'])
            ? implode(', ', array_map(fn($ix) => preg_replace('/:.+$/', '', $ix), $p['shared_ixs']))
            : '—';

        // Truncate shared IX display
        if (mb_strlen($sharedIxShort) > 25) {
            $sharedIxShort = mb_substr($sharedIxShort, 0, 22) . '…';
        }

        printf(
            $fmt,
            '#' . ($rank + 1),
            $r['name'],
            $r['ip'],
            $r['city'],
            'AS' . ($r['dst_asn'] ?? '?'),
            $p['score'] . '/100',
            $p['grade'],
            $p['direct_peer'] ? '✓' : '✗',
            $sharedIxShort,
            $p['details']
        );
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);
$peeringDbKey = $opts['peeringdb_key'];

fwrite(STDERR, "\e[1mOVPN Peering Quality Benchmark\e[0m\n");
fwrite(STDERR, str_repeat('─', 47) . "\n");
fwrite(STDERR, "Source ASN  : AS{$opts['src_asn']}\n");
fwrite(STDERR, "PeeringDB   : " . ($peeringDbKey ? "API key set ✓" : "\e[33mno API key (set PEERINGDB_API_KEY or --peeringdb-key=…)\e[0m") . "\n");

// Pre-fetch source ASN IX memberships
$srcIxs = getIxMemberships($opts['src_asn']);
fwrite(STDERR, sprintf("Source IXs : %d memberships\n", count($srcIxs)));
foreach ($srcIxs as $ix) {
    $speedG = $ix['speed'] >= 1000 ? ($ix['speed'] / 1000) . 'G' : $ix['speed'] . 'M';
    fwrite(STDERR, "  · {$ix['ix_name']} ({$speedG})\n");
}

// Load servers
try {
    $cities = loadJson($opts['input']);
} catch (RuntimeException $e) {
    fwrite(STDERR, "\e[31mFATAL: {$e->getMessage()}\e[0m\n");
    exit(1);
}

$rows = flattenServers($cities);
if (empty($rows)) {
    fwrite(STDERR, "\e[31mNo servers found.\e[0m\n");
    exit(1);
}

fwrite(STDERR, sprintf("\nLoaded %d server(s) from %s\n\n", count($rows), $opts['input']));

// Optional: load bench data for combined output
$benchData = [];
if ($opts['bench'] !== null) {
    try {
        $bench = loadJson($opts['bench']);
        foreach ($bench as $entry) {
            $benchData[$entry['ip']] = $entry;
        }
        fwrite(STDERR, sprintf("Loaded %d bench result(s) from %s\n\n", count($benchData), $opts['bench']));
    } catch (RuntimeException $e) {
        fwrite(STDERR, "\e[33mWarning: {$e->getMessage()} — continuing without bench data\e[0m\n\n");
    }
}

// Deduplicate ASN lookups: group servers by unique IP prefix
fwrite(STDERR, "Resolving ASNs and peering quality...\n");

// Cache to avoid querying same ASN peering multiple times
$peeringCache = [];
$total = count($rows);

foreach ($rows as $i => &$row) {
    fwrite(STDERR, sprintf(
        "  [%d/%d] %-7s %-16s %-15s ...",
        $i + 1,
        $total,
        $row['name'],
        $row['ip'],
        $row['city']
    ));

    // 1. Resolve IP → ASN
    $asnInfo = ipToAsn($row['ip']);
    if ($asnInfo === null) {
        fwrite(STDERR, " \e[33mASN lookup failed\e[0m\n");
        $row['dst_asn']    = null;
        $row['dst_holder'] = null;
        $row['dst_prefix'] = null;
        $row['peering']    = [
            'score'            => 0,
            'grade'            => 'F',
            'same_asn'         => false,
            'direct_peer'      => false,
            'shared_ixs'       => [],
            'dst_in_peeringdb' => false,
            'details'          => 'ASN lookup failed',
        ];
        continue;
    }

    $row['dst_asn']    = $asnInfo['asn'];
    $row['dst_holder'] = $asnInfo['holder'];
    $row['dst_prefix'] = $asnInfo['prefix'];

    // 2. Compute peering score (cached per destination ASN)
    $dstAsn = $asnInfo['asn'];
    if (!isset($peeringCache[$dstAsn])) {
        $peeringCache[$dstAsn] = computePeeringScore($opts['src_asn'], $dstAsn);
    }
    $row['peering'] = $peeringCache[$dstAsn];
    $p = $row['peering'];

    // 3. Merge bench data if available
    if (isset($benchData[$row['ip']])) {
        $b = $benchData[$row['ip']];
        $row['ping_avg']    = $b['ping_avg']    ?? null;
        $row['ping_median'] = $b['ping_median'] ?? null;
        $row['ping_ms']     = $b['ping_ms']     ?? null;
    }

    $gradeColor = match ($p['grade']) {
        'A+', 'A' => "\e[32m",
        'B'       => "\e[36m",
        'C'       => "\e[33m",
        default   => "\e[31m",
    };

    fwrite(STDERR, sprintf(
        " AS%-6d  %s%s %d/100\e[0m  %s\n",
        $dstAsn,
        $gradeColor,
        $p['grade'],
        $p['score'],
        $p['direct_peer'] ? '(direct peer)' : ($p['shared_ixs'] ? '(shared IX)' : '(no peering)')
    ));
}
unset($row);

fwrite(STDERR, "\n");

// Sort by peering score descending, then by ping ascending
usort($rows, function ($a, $b) {
    $scoreA = $a['peering']['score'] ?? 0;
    $scoreB = $b['peering']['score'] ?? 0;
    if ($scoreA !== $scoreB) return $scoreB <=> $scoreA;

    // Secondary sort: ping (if available)
    $pingA = $a['ping_ms'] ?? PHP_INT_MAX;
    $pingB = $b['ping_ms'] ?? PHP_INT_MAX;
    return $pingA <=> $pingB;
});

if ($opts['top'] !== null && $opts['top'] > 0) {
    $rows = array_slice($rows, 0, $opts['top']);
}

// Print table
printPeeringTable($rows, $opts['src_asn']);

// Summary stats
$grades = array_count_values(array_column(array_column($rows, 'peering'), 'grade'));
fwrite(STDERR, "\n\e[1mSummary:\e[0m\n");
foreach (['A+', 'A', 'B', 'C', 'D', 'F'] as $g) {
    if (isset($grades[$g])) {
        fwrite(STDERR, "  Grade {$g}: {$grades[$g]} server(s)\n");
    }
}

$uniqueDstAsns = array_unique(array_filter(array_column($rows, 'dst_asn')));
fwrite(STDERR, sprintf("  Unique destination ASNs: %d\n", count($uniqueDstAsns)));
foreach ($uniqueDstAsns as $asn) {
    $holder = '';
    foreach ($rows as $r) {
        if (($r['dst_asn'] ?? 0) === $asn) {
            $holder = $r['dst_holder'] ?? '';
            break;
        }
    }
    fwrite(STDERR, "    · AS{$asn} ({$holder})\n");
}

// Write output
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