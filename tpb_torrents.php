#!/usr/bin/env php
<?php

/*
 * Download the latest E-books torrents from The Pirate Bay via Transmission.
 *
 * Torrents are downloaded and seeded in a hidden staging directory
 * (downloads/torrents/.seeding/<info-hash>) so uploaddirectory.php will not
 * touch them while they are still downloading or seeding. When the configured
 * seed ratio is reached the files are moved to downloads/torrents, where
 * uploaddirectory.php can find and process them.
 *
 * Usage:
 *   php tpb_torrents.php [options]
 *
 *   --pages N             Result pages to scan (default: 1)
 *   --limit N             Maximum number of new torrents to add (default: 10,
 *                         0 = unlimited)
 *   --min-seeders N       Skip torrents with fewer seeders (default: 1)
 *   --min-size SIZE       Skip torrents smaller than SIZE (e.g. 1MiB)
 *   --max-size SIZE       Skip torrents larger than SIZE (e.g. 2GiB)
 *   --ratio N             Seed ratio to reach before releasing files (default: 3)
 *   --idle-seeding-limit N
 *                         Stop seeding and release files after N minutes without
 *                         activity (default: 240, 0 = no idle limit)
 *   --host HOST[:PORT]    Transmission RPC address (default: localhost:9091)
 *   --auth USER:PASSWORD  Transmission RPC credentials
 *   --dry-run             Only show what would be downloaded
 *   --maintain            Only release torrents that have reached the ratio
 *   --no-maintain         Don't release torrents before downloading new ones
 *   --install-hook        Tell Transmission to run this script when seeding
 *                         reaches the ratio (runs the script immediately)
 *   --disable-done-script Use with --install-hook to also disable Transmission's
 *                         done-downloading script, so it cannot upload/remove
 *                         files before the seed ratio is reached
 *   --uninstall-hook      Disable Transmission's done-seeding script
 *   --help
 *
 * Environment overrides:
 *   TPB_DOWNLOAD_DIR, TPB_HOST, TPB_AUTH, TPB_SEED_RATIO, TPB_IDLE_SEEDING_LIMIT,
 *   TPB_LIMIT
 */

require_once('booktools.php');

const TPB_BROWSE_URL = 'https://tpb.party/browse/601';
const TPB_TRANSMISSION_BIN = 'transmission-remote';

function ensure_dir($dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create directory: $dir");
    }
}

function parse_options($argv) {
    $options = [
        'pages' => 1,
        'limit' => (int) (getenv('TPB_LIMIT') !== false ? getenv('TPB_LIMIT') : 10),
        'min-seeders' => 1,
        'min-size' => 0,
        'max-size' => 0,
        'ratio' => (float) (getenv('TPB_SEED_RATIO') !== false ? getenv('TPB_SEED_RATIO') : 3),
        'idle-seeding-limit' => (int) (getenv('TPB_IDLE_SEEDING_LIMIT') !== false ? getenv('TPB_IDLE_SEEDING_LIMIT') : 240),
        'host' => getenv('TPB_HOST') ?: 'localhost:9091',
        'auth' => getenv('TPB_AUTH') ?: '',
        'dry-run' => false,
        'maintain' => false,
        'no-maintain' => false,
        'install-hook' => false,
        'uninstall-hook' => false,
        'disable-done-script' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        $value = null;
        if (str_contains($arg, '=')) {
            [$arg, $value] = explode('=', $arg, 2);
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--dry-run' || $arg === '--maintain' || $arg === '--no-maintain'
            || $arg === '--install-hook' || $arg === '--uninstall-hook'
            || $arg === '--disable-done-script') {
            $options[substr($arg, 2)] = true;
            continue;
        }

        $known = [
            'pages', 'limit', 'min-seeders', 'min-size', 'max-size', 'ratio',
            'idle-seeding-limit', 'idle-limit', 'host', 'auth',
        ];
        $name = ltrim($arg, '-');
        if (in_array($name, $known, true)) {
            if ($value === null) {
                if (!isset($argv[$i + 1])) {
                    fwrite(STDERR, "Missing value for $arg\n");
                    exit(2);
                }
                $value = $argv[++$i];
            }
            if ($name === 'idle-limit') {
                $name = 'idle-seeding-limit';
            }
            if (in_array($name, ['min-size', 'max-size'], true)) {
                $value = parse_human_size($value);
            } elseif (in_array($name, ['pages', 'limit', 'min-seeders', 'idle-seeding-limit'], true)) {
                $value = (int) $value;
            } elseif ($name === 'ratio') {
                $value = (float) $value;
            }
            if ($name === 'idle-seeding-limit' && $value < 0) {
                fwrite(STDERR, "--idle-seeding-limit cannot be negative\n");
                exit(2);
            }
            $options[$name] = $value;
            continue;
        }

        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    }

    return $options;
}

function print_help() {
    $help = file_get_contents(__FILE__);
    if (preg_match('~/\*(.*?)\*/~s', $help, $m)) {
        echo preg_replace('~^\s*\* ?~m', '', $m[1]);
    }
}

function parse_human_size($value) {
    $value = trim(str_replace(["\xc2\xa0", ','], [' ', ''], $value));
    if ($value === '' || $value === '0') return 0;
    if (!preg_match('~^([0-9.]+)\s*([KMGTP]?)(?:i?B)?$~i', $value, $m)) {
        throw new InvalidArgumentException("Invalid size: $value");
    }
    $number = (float) $m[1];
    $power = strpos('KMGTP', strtoupper($m[2]));
    if ($m[2] !== '') {
        $number *= 1024 ** ($power + 1);
    }
    return (int) round($number);
}

function format_size($bytes) {
    if ($bytes <= 0) return '?';
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

function run_command(array $args, &$output = null) {
    $output = [];
    $code = 0;
    exec(escape_command($args) . ' 2>&1', $output, $code);
    return $code;
}

function run_transmission(array $args, &$output = null) {
    global $options;
    $command = [TPB_TRANSMISSION_BIN];
    if (!empty($options['host'])) $command[] = $options['host'];
    if (!empty($options['auth'])) {
        $command[] = '-n';
        $command[] = $options['auth'];
    }
    return run_command(array_merge($command, $args), $output);
}

function transmission_available() {
    $code = run_transmission(['-si'], $output);
    return $code === 0;
}

function transmission_rpc($method, $arguments = []) {
    global $options;

    $host = $options['host'];
    if (!preg_match('~^https?://~i', $host)) {
        $host = 'http://' . $host;
    }
    if (!str_contains($host, '/transmission/rpc')) {
        $host = rtrim($host, '/') . '/transmission/rpc';
    }

    $payload = json_encode(['method' => $method, 'arguments' => $arguments]);
    $sessionId = '';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $responseHeaders = [];
        $ch = curl_init($host);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_filter([
                'Content-Type: application/json',
                $sessionId !== '' ? "X-Transmission-Session-Id: $sessionId" : null,
            ]),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (preg_match('~^X-Transmission-Session-Id:\s*(.+)$~i', trim($line), $matches)) {
                    $responseHeaders['session'] = trim($matches[1]);
                }
                return strlen($line);
            },
        ];
        if ($options['auth'] !== '') {
            $curlOptions[CURLOPT_USERPWD] = $options['auth'];
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status === 409) {
            $sessionId = $responseHeaders['session'] ?? '';
            if ($sessionId === '') break;
            continue;
        }
        if ($response === false) {
            throw new RuntimeException("Transmission RPC failed: $error");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid Transmission RPC response (HTTP $status)");
        }
        if (($data['result'] ?? '') !== 'success') {
            throw new RuntimeException('Transmission RPC error: ' . ($data['result'] ?? 'unknown'));
        }
        return $data['arguments'] ?? [];
    }

    throw new RuntimeException('Could not obtain a Transmission RPC session id');
}

function load_seen($seenFile) {
    $seen = [];
    if (!file_exists($seenFile)) return $seen;
    foreach (file($seenFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", $line, 2);
        if (!empty($parts[0])) $seen[strtoupper(trim($parts[0]))] = true;
    }
    return $seen;
}

function mark_seen($seenFile, $hash, $title) {
    file_put_contents(
        $seenFile,
        strtoupper($hash) . "\t" . date('c') . "\t" . str_replace(["\t", "\n"], ' ', $title) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function fetch_torrent_page($page) {
    $url = TPB_BROWSE_URL . '/' . $page . '/3';
    $html = httpGet($url);
    $dom = parseHtml($html);
    $torrents = [];

    foreach ($dom->getElementsByTagName('tr') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagName('td') as $cell) {
            $cells[] = $cell;
        }
        if (count($cells) < 8) continue;

        $magnet = null;
        $detailsUrl = null;
        $title = '';
        foreach ($row->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if ($magnet === null && str_starts_with($href, 'magnet:')) {
                $magnet = $href;
            }
            if ($detailsUrl === null && preg_match('~/torrent/[0-9]+/~', $href)) {
                $detailsUrl = $href;
                $title = trim(preg_replace('~\s+~u', ' ', $link->textContent));
            }
        }
        if ($magnet === null) continue;

        if (!preg_match('~btih:([A-Za-z0-9]{32,40})~', $magnet, $matches)) continue;
        $hash = strtoupper($matches[1]);

        $size = parse_human_size($cells[4]->textContent);
        $seeders = (int) preg_replace('~\D~', '', $cells[5]->textContent);
        $leechers = (int) preg_replace('~\D~', '', $cells[6]->textContent);

        $torrents[] = [
            'hash' => $hash,
            'title' => $title !== '' ? $title : $hash,
            'magnet' => $magnet,
            'url' => $detailsUrl,
            'size' => $size,
            'seeders' => $seeders,
            'leechers' => $leechers,
            'date' => trim(preg_replace('~\s+~u', ' ', $cells[2]->textContent)),
        ];
    }

    return $torrents;
}

function path_is_within($path, $root) {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    return $path === $root || str_starts_with($path . '/', $root . '/');
}

function move_path($source, $destination, $tag) {
    if (!file_exists($destination)) {
        return @rename($source, $destination);
    }

    if (is_dir($source) && is_dir($destination)) {
        $ok = true;
        foreach (scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $ok = move_path("$source/$entry", "$destination/$entry", $tag) && $ok;
        }
        return $ok && @rmdir($source);
    }

    $dir = dirname($destination);
    $name = pathinfo($destination, PATHINFO_FILENAME);
    $extension = pathinfo($destination, PATHINFO_EXTENSION);
    $suffix = $extension !== '' ? ".$extension" : '';
    $newDestination = "$dir/$name ($tag)$suffix";
    echo "  Destination exists, using " . basename($newDestination) . "\n";
    return @rename($source, $newDestination);
}

function move_contents($sourceDir, $destinationDir, $tag) {
    $ok = true;
    foreach (scandir($sourceDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $ok = move_path("$sourceDir/$entry", "$destinationDir/$entry", $tag) && $ok;
    }
    return $ok;
}

function release_torrent($id, $torrentDir, $hash, $name, $options, $stagingDir, $downloadDir) {
    if (!path_is_within($torrentDir, $stagingDir)) {
        return false;
    }
    if (!is_dir($torrentDir)) {
        echo "Staging directory is already gone: $torrentDir\n";
    }

    $label = $name !== '' ? "\"$name\"" : basename($torrentDir);
    echo "Releasing $label (seed limit reached).\n";

    if ($options['dry-run']) {
        echo "  Would move $torrentDir to $downloadDir and remove the torrent.\n";
        return true;
    }

    $ok = true;
    if (is_dir($torrentDir)) {
        $ok = move_contents($torrentDir, $downloadDir, substr($hash, 0, 8));
        @rmdir($torrentDir);
    }

    if (!$ok) {
        fwrite(STDERR, "Could not move all files from $torrentDir; leaving torrent in Transmission.\n");
        return false;
    }

    if ($id !== '') {
        $code = run_transmission(['-t', $id, '-r'], $output);
        if ($code !== 0) {
            fwrite(STDERR, "Moved files, but could not remove torrent $id from Transmission.\n");
        }
    }

    echo "  Files are now in $downloadDir.\n";
    return true;
}

function maintain($options, $stagingDir, $downloadDir) {
    try {
        $result = transmission_rpc('torrent-get', [
            'fields' => [
                'id', 'hashString', 'name', 'downloadDir', 'percentDone',
                'uploadRatio', 'seedRatioMode', 'seedRatioLimit',
                'seedIdleMode', 'seedIdleLimit', 'etaIdle',
                'status', 'isFinished', 'isStalled',
            ],
        ]);
    } catch (Exception $e) {
        fwrite(STDERR, "Could not list Transmission torrents: " . $e->getMessage() . "\n");
        return 0;
    }

    $released = 0;

    foreach ($result['torrents'] ?? [] as $torrent) {
        $location = $torrent['downloadDir'] ?? '';
        if ($location === '' || !path_is_within($location, $stagingDir)) continue;
        if (($torrent['percentDone'] ?? 0) < 1.0) continue;

        $ratio = (float) ($torrent['uploadRatio'] ?? 0);

        // Prefer the ratio that was set on the torrent when it was added.
        $targetRatio = $options['ratio'];
        if (($torrent['seedRatioMode'] ?? 0) === 1
            && isset($torrent['seedRatioLimit'])
            && (float) $torrent['seedRatioLimit'] > 0) {
            $targetRatio = (float) $torrent['seedRatioLimit'];
        }
        $ratioReached = $ratio + 0.0001 >= $targetRatio;

        // Transmission stops the torrent when its idle limit is reached and
        // reports it as finished + stalled with no remaining idle ETA.
        $idleReached = ($torrent['status'] ?? -1) === 0
            && !empty($torrent['isFinished'])
            && ($torrent['seedIdleMode'] ?? 0) === 1
            && (!empty($torrent['isStalled']) || ($torrent['etaIdle'] ?? 0) === -1);

        if (!$ratioReached && !$idleReached) continue;

        $id = (string) ($torrent['id'] ?? '');
        $hash = $torrent['hashString'] ?? basename($location);
        $name = $torrent['name'] ?? '';
        if (release_torrent($id, $location, $hash, $name, $options, $stagingDir, $downloadDir)) {
            $released += 1;
        }
    }

    if ($released > 0) {
        echo "Released $released torrent(s).\n";
    }

    return $released;
}

function handle_seeding_done($options, $stagingDir, $downloadDir) {
    $torrentDir = getenv('TR_TORRENT_DIR');
    $id = getenv('TR_TORRENT_ID') ?: '';
    $hash = getenv('TR_TORRENT_HASH') ?: basename($torrentDir);
    $name = getenv('TR_TORRENT_NAME') ?: '';

    // This hook is global in Transmission. Ignore torrents that are not ours.
    if ($torrentDir === false || $torrentDir === '' || !is_dir($torrentDir)) {
        return 0;
    }
    if (!path_is_within($torrentDir, $stagingDir)) {
        return 0;
    }

    return release_torrent($id, $torrentDir, $hash, $name, $options, $stagingDir, $downloadDir) ? 0 : 1;
}

function install_hook($options) {
    $script = realpath(__FILE__);
    try {
        $session = transmission_rpc('session-get');

        // transmission-remote can set the filename but not the "enabled" flag.
        transmission_rpc('session-set', [
            'script-torrent-done-seeding-enabled' => true,
            'script-torrent-done-seeding-filename' => $script,
        ]);
        echo "Transmission will now run $script when seeding reaches the ratio.\n";

        if (!empty($session['script-torrent-done-enabled'])) {
            $doneScript = $session['script-torrent-done-filename'] ?? '';
            if ($options['disable-done-script']) {
                transmission_rpc('session-set', ['script-torrent-done-enabled' => false]);
                echo "Disabled the done-downloading script ($doneScript).\n";
            } else {
                echo "Warning: Transmission's done-downloading script is enabled ($doneScript).\n";
                echo "If it processes files as soon as a download completes, it may upload/delete\n";
                echo "them before the seed ratio is reached. Re-run with --disable-done-script\n";
                echo "to turn it off.\n";
            }
        }

        return 0;
    } catch (Exception $e) {
        fwrite(STDERR, "Could not install done-seeding hook: " . $e->getMessage() . "\n");
        return 1;
    }
}

function uninstall_hook() {
    try {
        transmission_rpc('session-set', ['script-torrent-done-seeding-enabled' => false]);
        echo "Transmission's done-seeding script has been disabled.\n";
        return 0;
    } catch (Exception $e) {
        fwrite(STDERR, "Could not disable done-seeding hook: " . $e->getMessage() . "\n");
        return 1;
    }
}

function set_torrent_idle_limit($hash, $minutes) {
    $arguments = [
        'ids' => [$hash],
        // 1 = use the per-torrent limit, 2 = no idle limit.
        'seedIdleMode' => $minutes > 0 ? 1 : 2,
    ];
    if ($minutes > 0) {
        $arguments['seedIdleLimit'] = $minutes;
    }
    transmission_rpc('torrent-set', $arguments);
}

function add_torrent($torrent, $options, $stagingDir) {
    $stageDir = $stagingDir . '/' . $torrent['hash'];
    ensure_dir($stageDir);

    $args = [
        '-w', $stageDir,
        '-sr', (string) $options['ratio'],
        '-a', $torrent['magnet'],
    ];
    $code = run_transmission($args, $output);
    if ($code !== 0) {
        fwrite(STDERR, "Failed to add \"$torrent[title]\":\n" . implode("\n", $output) . "\n");
        return false;
    }

    try {
        set_torrent_idle_limit($torrent['hash'], $options['idle-seeding-limit']);
    } catch (Exception $e) {
        fwrite(STDERR, "Added \"$torrent[title]\", but could not set the idle limit: "
            . $e->getMessage() . "\n");
    }

    return true;
}

$options = parse_options($argv ?? []);

if ($options['help']) {
    print_help();
    exit(0);
}
if ($options['install-hook']) {
    exit(install_hook($options));
}
if ($options['uninstall-hook']) {
    exit(uninstall_hook());
}

$downloadDir = getenv('TPB_DOWNLOAD_DIR') ?: __DIR__ . '/downloads/torrents';
ensure_dir($downloadDir);
$downloadDir = realpath($downloadDir);
$stagingDir = "$downloadDir/.seeding";
$stateDir = "$downloadDir/.state";
$seenFile = "$stateDir/seen.txt";
ensure_dir($stagingDir);
ensure_dir($stateDir);

// Transmission invokes this same script as the done-seeding hook.
if (getenv('TR_TORRENT_DIR') !== false) {
    exit(handle_seeding_done($options, $stagingDir, $downloadDir));
}

// Prevent two cron jobs from adding the same torrents at once.
$lock = fopen("$stateDir/lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another tpb_torrents.php run is already in progress.\n";
    exit(0);
}

if (!transmission_available()) {
    fwrite(STDERR, "Could not connect to Transmission at {$options['host']}.\n");
    fwrite(STDERR, "Set TPB_HOST (and TPB_AUTH if needed), or pass --host/--auth.\n");
    exit(1);
}

if ($options['dry-run']) {
    echo "Connected to Transmission at {$options['host']} (dry run).\n";
} else {
    echo "Connected to Transmission at {$options['host']}.\n";
}

if (!$options['no-maintain'] || $options['maintain']) {
    maintain($options, $stagingDir, $downloadDir);
}
if ($options['maintain']) {
    exit(0);
}

$seen = load_seen($seenFile);
echo 'Already seen ' . count($seen) . " torrent(s).\n";

$added = 0;
$scanned = 0;

for ($page = 1; $page <= $options['pages']; $page++) {
    try {
        $torrents = fetch_torrent_page($page);
    } catch (Exception $e) {
        fwrite(STDERR, "Could not fetch page $page: " . $e->getMessage() . "\n");
        break;
    }

    echo 'Page ' . $page . ': ' . count($torrents) . " torrent(s).\n";
    if (empty($torrents)) break;

    foreach ($torrents as $torrent) {
        $scanned += 1;

        if (isset($seen[$torrent['hash']])) continue;
        if ($torrent['seeders'] < $options['min-seeders']) continue;
        if ($options['min-size'] > 0 && $torrent['size'] < $options['min-size']) continue;
        if ($options['max-size'] > 0 && $torrent['size'] > $options['max-size']) continue;

        if ($options['limit'] > 0 && $added >= $options['limit']) break 2;

        echo sprintf(
            "+ %s \"%s\" (%s, %d seeder%s)\n",
            $torrent['hash'],
            $torrent['title'],
            format_size($torrent['size']),
            $torrent['seeders'],
            $torrent['seeders'] === 1 ? '' : 's'
        );

        if ($options['dry-run']) {
            $added += 1;
            continue;
        }

        if (!add_torrent($torrent, $options, $stagingDir)) continue;

        mark_seen($seenFile, $torrent['hash'], $torrent['title']);
        $seen[$torrent['hash']] = true;
        $added += 1;
    }
}

if ($options['dry-run']) {
    echo "Would add $added torrent(s) (scanned $scanned).\n";
} else {
    echo "Added $added torrent(s) (scanned $scanned).\n";
}
