<?php
/**
 * Download article PDFs that people share in r/Scholar comments.
 *
 * Requests in r/Scholar are often fulfilled one or more days after they are
 * posted, so this script does not look at new posts.  It reads the comment RSS
 * feed instead and picks up every comment that contains a link.  Most
 * fulfillments use MediaFire; those are downloaded with mdrs from
 * others/mediafire_rs-main.  Direct PDF links and Google Drive links are
 * handled as well.
 *
 * Examples:
 *
 *   # See which links are currently waiting to be downloaded
 *   php reddit_scholar.php --list
 *
 *   # Download everything that has not been seen before
 *   php reddit_scholar.php
 *
 *   # Download at most 10 files in this run
 *   php reddit_scholar.php --max 10
 *
 *   # Cron example: every 15 minutes
 *   0,15,30,45 * * * * cd /path/to/booktools && php reddit_scholar.php
 *
 * The script keeps its progress in .reddit-scholar-state.json inside the
 * output directory, so repeated runs only handle new links.  A tab-separated
 * downloads.tsv file records which paper title belongs to which file.
 */

const RS_DEFAULT_FEED = 'https://www.reddit.com/r/Scholar/comments.rss';
const RS_PAGE_SIZE = 100;

$options = rsParseOptions($argv);

if ($options['help']) {
    rsPrintHelp();
    exit(0);
}

$outDir = rtrim($options['out'], '/');
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: $outDir\n");
    exit(1);
}

$stateFile = $options['state'] ?: "$outDir/.reddit-scholar-state.json";
$manifestFile = $options['no-manifest']
    ? ''
    : ($options['manifest'] ?: "$outDir/downloads.tsv");
$state = rsLoadState($stateFile);

$lock = rsAcquireLock("$stateFile.lock");
if (!$lock) {
    fwrite(STDERR, "Another reddit_scholar.php run is already using $stateFile\n");
    exit(1);
}

$stats = [
    'seen' => 0,
    'downloaded' => 0,
    'failed' => 0,
    'notpdf' => 0,
    'listed' => 0,
    'ignored' => 0,
    'deferred' => 0,
];

rsLog($options, 'Feed:   ' . $options['feed']);
rsLog($options, 'Output: ' . $outDir);
rsLog($options, 'State:  ' . $stateFile);

$entries = rsFetchEntries($options);
if ($entries === null) {
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

// Links in the feed that still need work.  Failed links are prepended below
// so that a link which scrolled out of the RSS window is still retried.
$pending = [];
$pendingOrder = [];
$seenThisRun = [];

foreach ($entries as $entry) {
    $commentId = $entry['comment_id'];

    if ($commentId !== '' && isset($seenThisRun[$commentId])) {
        continue;
    }
    if ($commentId !== '') {
        $seenThisRun[$commentId] = true;
    }

    // AutoModerator comments are templates with LibGen/Sci-Hub links, not
    // fulfillments.
    if (!empty($entry['automod'])) {
        continue;
    }

    foreach ($entry['links'] as $url) {
        $link = rsClassifyLink($url);
        if ($link === null) {
            $stats['ignored']++;
            rsLog($options, "Ignoring unsupported link: $url");
            continue;
        }

        $link['title'] = $entry['title'];
        $link['comment_id'] = $commentId;
        $link['comment_url'] = $entry['comment_url'];

        $key = $link['key'];
        $existing = $state['links'][$key] ?? null;

        if (is_array($existing) && in_array($existing['status'] ?? '', ['downloaded', 'notpdf'], true)) {
            $stats['seen']++;
            continue;
        }

        if (is_array($existing) && ($existing['status'] ?? '') === 'failed' && !$options['retry-failed']) {
            if (rsFailureExhausted($existing, $options)) {
                $stats['seen']++;
                continue;
            }
        }

        if (!isset($pending[$key])) {
            // Keep the newest occurrence: an old failed entry may be retried
            // from the failure queue anyway.
            $pending[$key] = $link;
            $pendingOrder[] = $key;
        }
    }

    if (!$options['list'] && !$options['dry-run'] && $commentId !== '') {
        $state['seen_comments'][$commentId] = gmdate('c');
    }
}

// Retry previously failed downloads first.  They are no longer guaranteed to
// be in the current RSS page.
$retries = rsCollectRetries($state, $options, $pending);
if ($retries) {
    $pending = $retries + $pending;
    $pendingOrder = array_merge(array_keys($retries), $pendingOrder);
}

rsPruneSeenComments($state);

$count = count($pending);
if ($options['max'] > 0 && $count > $options['max']) {
    $stats['deferred'] = $count - $options['max'];
    $pendingOrder = array_slice($pendingOrder, 0, $options['max']);
}

if (!$pendingOrder) {
    rsLog($options, 'No new links found.');
}

foreach ($pendingOrder as $key) {
    $link = $pending[$key];

    echo '[' . rsLinkLabel($link) . '] ' . rsCleanLine($link['title']) . "\n";
    echo '  ' . $link['url'] . "\n";

    if ($options['list'] || $options['dry-run']) {
        if (!empty($link['filename'])) {
            echo '  expected file: ' . $link['filename'] . "\n";
        }
        echo '  comment: ' . $link['comment_url'] . "\n";
        $stats['listed']++;
        continue;
    }

    $result = rsDownloadLink($link, $options, $outDir);
    rsRecordResult($state, $key, $link, $result);

    if ($result['status'] === 'downloaded') {
        $stats['downloaded']++;
        echo '  downloaded: ' . implode(', ', $result['files']) . "\n";
        rsAppendManifest($manifestFile, $link, $result);
    } elseif ($result['status'] === 'notpdf') {
        $stats['notpdf']++;
        echo '  NOT A PDF: ' . $result['error'] . "\n";
        if ($result['files']) {
            echo '  kept file: ' . implode(', ', $result['files']) . "\n";
        }
    } else {
        $stats['failed']++;
        echo '  FAILED: ' . $result['error'] . "\n";
    }

    rsSaveState($stateFile, $state);

    if ($options['delay'] > 0) {
        usleep((int) ($options['delay'] * 1000000));
    }
}

if (!$options['list'] && !$options['dry-run']) {
    $state['lastRun'] = gmdate('c');
    rsSaveState($stateFile, $state);
}

$summary = sprintf(
    'Done: %d downloaded, %d failed, %d not PDF, %d already seen, %d listed',
    $stats['downloaded'],
    $stats['failed'],
    $stats['notpdf'],
    $stats['seen'],
    $stats['listed']
);
if ($stats['deferred'] > 0) {
    $summary .= sprintf(', %d deferred (use --max 0 to disable the limit)', $stats['deferred']);
}
if ($stats['ignored'] > 0) {
    $summary .= sprintf(', %d unsupported link(s)', $stats['ignored']);
}
echo $summary . ".\n";

flock($lock, LOCK_UN);
fclose($lock);


function rsParseOptions(array $argv): array
{
    $defaults = [
        'feed' => RS_DEFAULT_FEED,
        'out' => 'reddit-scholar-pdfs',
        'state' => '',
        'manifest' => '',
        'no-manifest' => false,
        'pages' => 1,
        'per-page' => RS_PAGE_SIZE,
        'max' => 0,
        'max-attempts' => 3,
        'mdrs' => '',
        'mdrs-tries' => 2,
        'flare-solver' => '',
        'timeout' => 300,
        'delay' => 1.0,
        'cookie' => '',
        'user-agent' => '',
        'mediafire-only' => false,
        'retry-failed' => false,
        'list' => false,
        'dry-run' => false,
        'verbose' => false,
        'help' => false,
    ];

    $long = [
        'feed:', 'out:', 'state:', 'manifest:', 'no-manifest', 'pages:',
        'per-page:', 'max:', 'max-attempts:', 'mdrs:', 'mdrs-tries:',
        'flare-solver:', 'timeout:', 'delay:', 'cookie:', 'user-agent:',
        'mediafire-only', 'retry-failed', 'list', 'dry-run', 'verbose', 'help',
    ];

    $parsed = getopt('', $long);
    if ($parsed === false) {
        $parsed = [];
    }

    $opts = $defaults;
    foreach ($parsed as $name => $value) {
        $name = str_replace('_', '-', $name);
        if (array_key_exists($name, $opts)) {
            $opts[$name] = is_bool($opts[$name]) ? true : $value;
        }
    }

    foreach (['pages', 'per-page', 'max', 'max-attempts', 'mdrs-tries', 'timeout'] as $name) {
        $opts[$name] = (int) $opts[$name];
    }
    $opts['delay'] = max(0.0, (float) $opts['delay']);
    $opts['pages'] = max(1, $opts['pages']);
    $opts['per-page'] = max(1, min(100, $opts['per-page']));
    $opts['max'] = max(0, $opts['max']);
    $opts['max-attempts'] = max(1, $opts['max-attempts']);
    $opts['mdrs-tries'] = max(0, $opts['mdrs-tries']);
    $opts['timeout'] = max(10, $opts['timeout']);

    return $opts;
}

function rsPrintHelp(): void
{
    echo <<<HELP
Usage: php reddit_scholar.php [options]

Downloads PDFs linked in comments on https://www.reddit.com/r/Scholar/.
The comment Atom feed is used instead of the newest posts because requests are
usually fulfilled a day or more after they are posted.  MediaFire links are
downloaded with others/mediafire_rs-main/mdrs; direct PDF and Google Drive
links are downloaded directly.

Options:
  --feed URL          Comment RSS/Atom feed (default: r/Scholar comments.rss)
  --pages N           Number of 100-comment feed pages to read (default: 1)
  --per-page N        Comments per page, 1-100 (default: 100)
  --max N             Maximum links to handle per run, 0 = no limit (default: 0)
  --max-attempts N    Give up on a failed link after N attempts (default: 3)
  --out DIR           Output directory (default: reddit-scholar-pdfs)
  --state FILE        State file (default: DIR/.reddit-scholar-state.json)
  --manifest FILE     TSV file mapping papers to files (default: DIR/downloads.tsv)
  --no-manifest       Do not write the TSV manifest
  --mdrs PATH         Path to the mdrs binary (default: auto-detect)
  --mdrs-tries N      Retries per MediaFire download passed to mdrs (default: 2)
  --flare-solver URL  FlareSolverr URL passed to mdrs for Cloudflare challenges
  --timeout N         Seconds allowed per download (default: 300)
  --delay N           Seconds between downloads (default: 1)
  --cookie STRING     Optional Cookie header for HTTP requests
  --user-agent STRING Override the HTTP User-Agent
  --mediafire-only    Ignore direct PDF and Google Drive links
  --retry-failed      Retry failed links now, ignoring the retry backoff
  --list              Show what would be downloaded; do not download or save state
  --dry-run           Same as --list
  --verbose           Print extra diagnostics
  --help              Show this help

How it works:
  * Reddit's unauthenticated feed is rate-limited to roughly one request per
    minute.  With the default single page this is not a problem.  If you use
    --pages > 1 the script waits between requests when Reddit asks it to.
  * The first run downloads every MediaFire/PDF link in the fetched feed.
    Later runs skip links that are already in the state file.
  * If a download fails it is retried on later runs, even after its comment
    has scrolled out of the RSS window.
  * MediaFire files keep their original names.  downloads.tsv records the
    r/Scholar paper title, comment URL, and the file(s) for each link.

Typical cron entry (every 15 minutes):
  */15 * * * * cd /path/to/booktools && php reddit_scholar.php

HELP;
}

function rsLoadState(string $file): array
{
    if (!is_file($file)) {
        return ['version' => 1, 'lastRun' => null, 'seen_comments' => [], 'links' => []];
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Warning: cannot parse state file $file; starting with an empty state.\n");
        return ['version' => 1, 'lastRun' => null, 'seen_comments' => [], 'links' => []];
    }

    $data += ['version' => 1, 'lastRun' => null, 'seen_comments' => [], 'links' => []];
    if (!is_array($data['seen_comments'])) {
        $data['seen_comments'] = [];
    }
    if (!is_array($data['links'])) {
        $data['links'] = [];
    }
    return $data;
}

function rsSaveState(string $file, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, 'Cannot encode state: ' . json_last_error_msg() . "\n");
        return;
    }

    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        fwrite(STDERR, "Cannot write state file: $file\n");
    }
}

function rsAcquireLock(string $file)
{
    $handle = fopen($file, 'c');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function rsPruneSeenComments(array &$state): void
{
    if (count($state['seen_comments']) <= 5000) {
        return;
    }
    arsort($state['seen_comments']);
    $state['seen_comments'] = array_slice($state['seen_comments'], 0, 2000, true);
}

function rsFetchEntries(array $options): ?array
{
    $entries = [];
    $after = '';
    $offset = 0;

    for ($page = 1; $page <= $options['pages']; $page++) {
        $params = ['limit' => $options['per-page']];
        if ($after !== '') {
            $params['after'] = $after;
            $params['count'] = $offset;
        }

        $url = $options['feed'];
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);

        $response = rsFetchFeedPage($url, $options);
        if ($response === null) {
            return $entries ?: null;
        }

        $pageEntries = rsParseFeed($response['body'], $options);
        if (!$pageEntries) {
            break;
        }

        foreach ($pageEntries as $entry) {
            $entries[] = $entry;
        }

        $last = end($pageEntries);
        $after = $last['full_id'];
        $offset += count($pageEntries);

        if (count($pageEntries) < $options['per-page']) {
            break;
        }

        if ($page < $options['pages']) {
            rsWaitForRateLimit($response['headers'], $options);
        }
    }

    return $entries;
}

function rsFetchFeedPage(string $url, array $options): ?array
{
    $maxAttempts = 4;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => rsFeedUserAgent($options),
            CURLOPT_HTTPHEADER => [
                'Accept: application/atom+xml,application/xml;q=0.9,text/xml;q=0.8,*/*;q=0.5',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (preg_match('~^HTTP/~', $trimmed)) {
                    $headers = [];
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false) {
                    $headers[strtolower(substr($trimmed, 0, $pos))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        if (!empty($options['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $remaining = isset($headers['x-ratelimit-remaining'])
            ? (float) $headers['x-ratelimit-remaining']
            : 1.0;

        if ($body !== false && trim((string) $body) !== ''
            && $status >= 200 && $status < 300
            && !($remaining <= 0 && stripos((string) $body, '<feed') === false)) {
            return ['body' => (string) $body, 'headers' => $headers];
        }

        $isRateLimit = $status === 429
            || (($status >= 200 && $status < 300) && (trim((string) $body) === '' || $remaining <= 0));
        $isRetryable = $isRateLimit || $status === 0 || $status >= 500;

        if (!$isRetryable) {
            rsLog($options, "Feed request failed (HTTP $status" . ($error ? ", $error" : '') . '): ' . $url);
            return null;
        }

        if ($attempt >= $maxAttempts) {
            rsLog($options, "Feed request failed after $maxAttempts attempts: " . $url);
            return null;
        }

        $wait = rsRateLimitWaitSeconds($headers);
        if ($isRateLimit && $wait < 1) {
            $wait = 60;
        } elseif ($wait < 1) {
            $wait = 2 * $attempt;
        }

        rsLog($options, "Reddit rate limit (HTTP $status); waiting {$wait}s before retrying.");
        sleep($wait);
    }

    return null;
}

function rsWaitForRateLimit(array $headers, array $options): void
{
    $remaining = isset($headers['x-ratelimit-remaining'])
        ? (float) $headers['x-ratelimit-remaining']
        : 1.0;
    if ($remaining > 0) {
        return;
    }

    $wait = rsRateLimitWaitSeconds($headers);
    if ($wait < 1) {
        $wait = 60;
    }
    rsLog($options, "Reddit allows one request per minute; waiting {$wait}s before the next page.");
    sleep($wait);
}

function rsRateLimitWaitSeconds(array $headers): int
{
    if (isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
        return min(300, max(1, (int) ceil((float) $headers['retry-after'])));
    }
    if (isset($headers['x-ratelimit-reset']) && is_numeric($headers['x-ratelimit-reset'])) {
        return min(300, max(1, (int) ceil((float) $headers['x-ratelimit-reset']) + 1));
    }
    return 0;
}

function rsParseFeed(string $xmlString, array $options): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string(
        $xmlString,
        'SimpleXMLElement',
        LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        rsLog($options, 'Cannot parse the Reddit feed as XML.');
        return [];
    }

    $entries = [];
    foreach ($xml->entry as $entry) {
        $fullId = trim((string) $entry->id);
        $commentId = preg_replace('~^t1_~', '', $fullId);
        $author = ltrim(trim((string) $entry->author->name), '/');
        $author = preg_replace('~^u/~', '', $author);

        $commentUrl = trim((string) $entry->link['href']);
        $title = trim((string) $entry->title);
        $title = preg_replace('~^/u/\S+\s+on\s+~u', '', $title);

        $content = html_entity_decode(
            (string) $entry->content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $entries[] = [
            'full_id' => $fullId,
            'comment_id' => (string) $commentId,
            'comment_url' => $commentUrl,
            'title' => $title !== '' ? $title : '(no title)',
            'author' => $author,
            'links' => rsExtractUrls($content),
            'automod' => strcasecmp($author, 'AutoModerator') === 0,
        ];
    }

    return $entries;
}

function rsExtractUrls(string $html): array
{
    $urls = [];

    if (preg_match_all('~href\s*=\s*(["\'])(.*?)\1~is', $html, $matches)) {
        foreach ($matches[2] as $url) {
            $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    // Some comments contain a bare URL rather than an anchor.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match_all('~https?://[^\s<>"\']+~i', $text, $matches)) {
        foreach ($matches[0] as $url) {
            $urls[] = $url;
        }
    }

    $clean = [];
    foreach ($urls as $url) {
        $url = trim($url);
        $url = rtrim($url, ".,;:!?)]}\u{2019}\u{201D}'\"");
        $url = str_replace('&amp;', '&', $url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            continue;
        }
        $clean[$url] = true;
    }

    return array_keys($clean);
}

function rsClassifyLink(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    $hostNoWww = preg_replace('~^www\.~', '', $host);
    $path = $parts['path'] ?? '';
    $query = $parts['query'] ?? '';

    if ($hostNoWww === 'mediafire.com' || str_ends_with($hostNoWww, '.mediafire.com')) {
        $type = 'mediafire';
        $key = '';
        $filename = '';

        if (preg_match('~^/(file|folder)/([^/]+)(?:/([^/]+))?~', $path, $m)) {
            $type = $m[1] === 'folder' ? 'mediafire-folder' : 'mediafire';
            $key = 'mediafire:' . $m[1] . ':' . $m[2];
            if ($m[1] === 'file' && isset($m[3]) && $m[3] !== '' && $m[3] !== 'file') {
                $filename = rawurldecode($m[3]);
            }
        } elseif (preg_match('~^/download/([^/]+)(?:/([^/]+))?~', $path, $m)) {
            $key = 'mediafire:file:' . $m[1];
            if (isset($m[2]) && $m[2] !== '') {
                $filename = rawurldecode($m[2]);
            }
        } elseif (preg_match('~^/([A-Za-z0-9]{10,})(?:/|$)~', $path, $m)) {
            // Old short links such as mediafire.com/?quickkey and
            // mediafire.com/quickkey.
            $key = 'mediafire:file:' . $m[1];
        } elseif ($query !== '') {
            parse_str($query, $q);
            $quickKey = $q['quickkey'] ?? array_key_first($q);
            if (is_string($quickKey) && $quickKey !== '') {
                $key = 'mediafire:file:' . $quickKey;
            }
        }

        if ($key === '') {
            $key = 'mediafire:url:' . sha1(rsNormalizeUrl($url));
        }

        if ($filename !== '') {
            $filename = rsSanitizeFilename($filename);
            if ($filename === '') {
                $filename = '';
            }
        }

        return [
            'type' => $type,
            'key' => $key,
            'url' => $url,
            'download_url' => $url,
            'filename' => $filename,
        ];
    }

    if ($hostNoWww === 'drive.google.com' || $hostNoWww === 'docs.google.com') {
        $id = '';
        if (preg_match('~/file/d/([^/]+)~', $path, $m)) {
            $id = $m[1];
        } else {
            parse_str($query, $q);
            if (!empty($q['id'])) {
                $id = (string) $q['id'];
            }
        }
        if ($id !== '') {
            return [
                'type' => 'gdrive',
                'key' => 'gdrive:' . $id,
                'url' => $url,
                'download_url' => 'https://drive.google.com/uc?export=download&id=' . rawurlencode($id),
                'filename' => 'gdrive-' . rsSanitizeFilename($id) . '.pdf',
                'gdrive_id' => $id,
            ];
        }
    }

    if (preg_match('~\.pdf(?:$|[?&#])~i', $url)) {
        $filename = '';
        $base = basename(rawurldecode($path));
        if ($base !== '' && $base !== '/') {
            $filename = rsSanitizeFilename($base);
        }
        return [
            'type' => 'pdf',
            'key' => 'pdf:' . sha1(rsNormalizeUrl($url)),
            'url' => $url,
            'download_url' => $url,
            'filename' => $filename,
        ];
    }

    return null;
}

function rsNormalizeUrl(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $normalized = strtolower($parts['scheme'] ?? 'https') . '://'
        . strtolower($parts['host'] ?? '')
        . ($parts['path'] ?? '');
    if (!empty($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }
    return $normalized;
}

function rsCollectRetries(array $state, array $options, array $pending): array
{
    $retries = [];

    foreach ($state['links'] as $key => $entry) {
        if (($entry['status'] ?? '') !== 'failed' || isset($pending[$key])) {
            continue;
        }
        if (!is_array($entry) || empty($entry['url'])) {
            continue;
        }
        if (!$options['retry-failed'] && rsFailureExhausted($entry, $options)) {
            continue;
        }

        $link = [
            'type' => $entry['type'] ?? 'mediafire',
            'key' => $key,
            'url' => $entry['url'],
            'download_url' => $entry['download_url'] ?? $entry['url'],
            'filename' => $entry['filename'] ?? '',
            'title' => $entry['title'] ?? '(failed link)',
            'comment_id' => $entry['comment_id'] ?? '',
            'comment_url' => $entry['comment_url'] ?? '',
        ];
        if (!empty($entry['gdrive_id'])) {
            $link['gdrive_id'] = $entry['gdrive_id'];
        }
        $retries[$key] = $link;
    }

    return $retries;
}

function rsFailureExhausted(array $entry, array $options): bool
{
    $attempts = (int) ($entry['attempts'] ?? 0);
    if ($attempts >= $options['max-attempts']) {
        return true;
    }
    $next = strtotime((string) ($entry['next_attempt'] ?? '')) ?: 0;
    return $next > time();
}

function rsDownloadLink(array $link, array $options, string $outDir): array
{
    if (str_starts_with($link['type'], 'mediafire')) {
        return rsDownloadMediafire($link, $options, $outDir);
    }
    if ($options['mediafire-only']) {
        return ['status' => 'failed', 'files' => [], 'error' => 'unsupported link type (--mediafire-only)'];
    }
    if ($link['type'] === 'gdrive' || $link['type'] === 'pdf') {
        return rsDownloadHttp($link, $options, $outDir);
    }

    return ['status' => 'failed', 'files' => [], 'error' => 'unsupported link type'];
}

function rsDownloadMediafire(array $link, array $options, string $outDir): array
{
    $expected = (string) ($link['filename'] ?? '');
    if ($expected !== '') {
        $found = rsFindFileByName($outDir, $expected);
        if ($found !== null && rsIsPdf($found)) {
            return ['status' => 'downloaded', 'files' => [rsRelativePath($outDir, $found)], 'error' => ''];
        }
    }

    $mdrs = rsFindMdrs((string) $options['mdrs']);
    if ($mdrs === '') {
        return [
            'status' => 'failed',
            'files' => [],
            'error' => 'mdrs not found; build it in others/mediafire_rs-main or pass --mdrs PATH',
        ];
    }

    $before = rsSnapshotDir($outDir);
    $cmd = [
        $mdrs,
        '--output', $outDir,
        '--max', '1',
        '--tries', (string) $options['mdrs-tries'],
    ];
    if (!empty($options['flare-solver'])) {
        $cmd[] = '--flare-solver';
        $cmd[] = $options['flare-solver'];
    }
    $cmd[] = $link['url'];

    rsLog($options, 'Running: ' . implode(' ', array_map('escapeshellarg', $cmd)));
    [$exit, $stdout, $stderr, $timedOut] = rsRunProcess($cmd, $options['timeout']);

    if ($timedOut) {
        return ['status' => 'failed', 'files' => [], 'error' => 'mdrs timed out after ' . $options['timeout'] . 's'];
    }

    $after = rsSnapshotDir($outDir);
    $changed = [];
    foreach ($after as $rel => $meta) {
        if (!isset($before[$rel])
            || $before[$rel]['size'] !== $meta['size']
            || $before[$rel]['mtime'] !== $meta['mtime']) {
            $changed[] = $rel;
        }
    }

    $pdfs = [];
    $others = [];
    foreach ($changed as $rel) {
        $path = "$outDir/$rel";
        if (rsIsPdf($path)) {
            $pdfs[] = $rel;
        } else {
            $others[] = $rel;
        }
    }

    if ($pdfs) {
        return ['status' => 'downloaded', 'files' => array_merge($pdfs, $others), 'error' => ''];
    }

    if ($expected !== '') {
        $found = rsFindFileByName($outDir, $expected);
        if ($found !== null && rsIsPdf($found)) {
            return ['status' => 'downloaded', 'files' => [rsRelativePath($outDir, $found)], 'error' => ''];
        }
    }

    if ($changed) {
        return [
            'status' => 'notpdf',
            'files' => $changed,
            'error' => 'mdrs downloaded ' . count($changed) . ' file(s), but none is a valid PDF',
        ];
    }

    $output = trim($stdout . "\n" . $stderr);
    if ($output === '') {
        $output = 'no file was produced';
    }
    $output = rsCleanLine(preg_replace('~\s+~', ' ', $output));
    return [
        'status' => 'failed',
        'files' => [],
        'error' => 'mdrs exit ' . $exit . ': ' . mb_substr($output, 0, 400),
    ];
}

function rsDownloadHttp(array $link, array $options, string $outDir): array
{
    $url = (string) ($link['download_url'] ?? $link['url']);
    $isGdrive = ($link['type'] ?? '') === 'gdrive';
    $tmp = $outDir . '/.part.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $cookieJar = $outDir . '/.cookies.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $maxAttempts = 3;
    $lastError = 'download failed';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        @unlink($tmp);
        $headers = [];
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($cookieJar);
            return ['status' => 'failed', 'files' => [], 'error' => 'cannot create temporary file in ' . $outDir];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_USERAGENT => rsUserAgent($options),
            CURLOPT_AUTOREFERER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf,application/octet-stream;q=0.9,*/*;q=0.5',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (preg_match('~^HTTP/~', $trimmed)) {
                    $headers = [];
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false) {
                    $headers[strtolower(substr($trimmed, 0, $pos))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        if (!empty($options['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
        }
        if ($isGdrive) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        // PHP 8's curl_close() is a no-op; unset the handle so that it writes
        // its cookie jar before we try to delete the temporary file below.
        unset($ch);
        fclose($handle);

        if ($ok && $status >= 200 && $status < 300 && rsIsPdf($tmp)) {
            $name = rsFilenameFromDisposition($headers['content-disposition'] ?? '');
            if ($name === '') {
                $name = (string) ($link['filename'] ?? '');
            }
            if ($name === '') {
                $name = basename((string) parse_url($effectiveUrl !== '' ? $effectiveUrl : $url, PHP_URL_PATH));
            }
            if ($name === '' || $name === '/' || !str_contains($name, '.')) {
                $name = ($link['type'] ?? 'paper') . '-' . substr(sha1((string) $link['key']), 0, 12) . '.pdf';
            }

            $name = rsSanitizeFilename($name);
            if ($name === '') {
                $name = 'paper-' . substr(sha1((string) $link['key']), 0, 12) . '.pdf';
            }
            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                $name .= '.pdf';
            }

            $target = rsUniqueFilePath($outDir, $name);
            if (!rename($tmp, $target)) {
                @unlink($tmp);
                @unlink($cookieJar);
                return ['status' => 'failed', 'files' => [], 'error' => "cannot move downloaded file to $target"];
            }
            @unlink($cookieJar);

            return [
                'status' => 'downloaded',
                'files' => [rsRelativePath($outDir, $target)],
                'error' => '',
            ];
        }

        if ($ok && $status >= 200 && $status < 300) {
            $lastError = 'downloaded file is not a PDF'
                . ($contentType !== '' ? " (content-type $contentType)" : '')
                . ', ' . (int) @filesize($tmp) . ' bytes';

            if ($isGdrive) {
                $html = (string) @file_get_contents($tmp);
                $nextUrl = rsGdriveConfirmUrl($html, $link);
                if ($nextUrl !== null) {
                    $url = $nextUrl;
                    continue;
                }
            }

            @unlink($tmp);
            @unlink($cookieJar);
            return ['status' => 'notpdf', 'files' => [], 'error' => $lastError];
        }

        $lastError = trim(($error !== '' ? $error : 'HTTP ' . $status)
            . ($contentType !== '' ? " ($contentType)" : ''));
        if (($status === 429 || $status >= 500 || $status === 0) && $attempt < $maxAttempts) {
            sleep(2 * $attempt);
            continue;
        }

        @unlink($tmp);
        @unlink($cookieJar);
        return ['status' => 'failed', 'files' => [], 'error' => $lastError];
    }

    @unlink($tmp);
    @unlink($cookieJar);
    return ['status' => 'failed', 'files' => [], 'error' => $lastError];
}

function rsGdriveConfirmUrl(string $html, array $link): ?string
{
    if ($html === '' || stripos($html, '<form') === false) {
        return null;
    }

    $action = 'https://drive.usercontent.google.com/download';
    if (preg_match('~<form[^>]+action\s*=\s*["\']([^"\']+)["\']~i', $html, $m)) {
        $action = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $params = [];
    if (preg_match_all('~<input\b[^>]*>~i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('~name\s*=\s*["\']([^"\']+)["\']~i', $tag, $name)
                && preg_match('~value\s*=\s*["\']([^"\']*)["\']~i', $tag, $value)) {
                $params[html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] =
                    html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }

    if (empty($params['confirm']) && preg_match('~confirm=([0-9A-Za-z_-]+)~', $html, $m)) {
        $params['confirm'] = $m[1];
    }
    if (empty($params['confirm'])) {
        return null;
    }

    if (!empty($link['gdrive_id'])) {
        $params['id'] = $link['gdrive_id'];
    }
    $params['export'] = 'download';

    return $action . (str_contains($action, '?') ? '&' : '?') . http_build_query($params);
}

function rsSnapshotDir(string $dir): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $rel = substr($full, strlen($dir) + 1);
        $base = basename($rel);
        if ($base === '' || $base[0] === '.' || str_contains($base, '.part.')) {
            continue;
        }
        $files[$rel] = ['size' => $file->getSize(), 'mtime' => $file->getMTime()];
    }
    ksort($files);
    return $files;
}

function rsFindFileByName(string $dir, string $name): ?string
{
    $direct = "$dir/$name";
    if (is_file($direct)) {
        return $direct;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strcasecmp($file->getFilename(), $name) === 0) {
            return $file->getPathname();
        }
    }

    return null;
}

function rsUniqueFilePath(string $dir, string $name): string
{
    $target = "$dir/$name";
    if (!file_exists($target)) {
        return $target;
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    for ($i = 2; $i < 1000; $i++) {
        $candidate = $base . ' (' . $i . ')' . ($extension !== '' ? '.' . $extension : '');
        $path = "$dir/$candidate";
        if (!file_exists($path)) {
            return $path;
        }
    }

    return "$dir/$base-" . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');
}

function rsFilenameFromDisposition(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match("~filename\\*\\s*=\\s*UTF-8''([^;]+)~i", $value, $m)) {
        return rawurldecode(trim($m[1], " \t\"'"));
    }
    if (preg_match('~filename\s*=\s*"([^"]+)"~i', $value, $m)) {
        return trim($m[1]);
    }
    if (preg_match('~filename\s*=\s*([^;]+)~i', $value, $m)) {
        return trim($m[1], " \t\"'");
    }
    return '';
}

function rsSanitizeFilename(string $name): string
{
    $name = rawurldecode($name);
    $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
    $name = preg_replace('/[\x00-\x1F\x7F<>"\/\\\\|?*]/u', ' ', $name);
    $name = preg_replace('~\s+~u', ' ', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    $name = mb_substr($name, 0, 200, 'UTF-8');
    return trim($name, ' .');
}

function rsIsPdf(string $path): bool
{
    if (!is_file($path) || filesize($path) === 0) {
        return false;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = (string) fread($handle, 1024);
    fclose($handle);

    return (bool) preg_match('~%PDF-\d\.\d~', $head);
}

function rsRecordResult(array &$state, string $key, array $link, array $result): void
{
    $entry = $state['links'][$key] ?? [];
    if (!is_array($entry)) {
        $entry = [];
    }

    $entry['type'] = $link['type'];
    $entry['url'] = $link['url'];
    $entry['download_url'] = $link['download_url'] ?? $link['url'];
    $entry['filename'] = $link['filename'] ?? '';
    $entry['title'] = $link['title'] ?? '';
    $entry['comment_id'] = $link['comment_id'] ?? '';
    $entry['comment_url'] = $link['comment_url'] ?? '';
    if (!empty($link['gdrive_id'])) {
        $entry['gdrive_id'] = $link['gdrive_id'];
    }
    if (empty($entry['first_seen'])) {
        $entry['first_seen'] = gmdate('c');
    }

    $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
    $entry['updated_at'] = gmdate('c');
    $entry['files'] = $result['files'] ?? [];
    $entry['error'] = $result['error'] ?? '';

    if (($result['status'] ?? '') === 'downloaded') {
        $entry['status'] = 'downloaded';
        $entry['downloaded_at'] = gmdate('c');
        unset($entry['next_attempt']);
    } elseif (($result['status'] ?? '') === 'notpdf') {
        $entry['status'] = 'notpdf';
        unset($entry['next_attempt']);
    } else {
        $entry['status'] = 'failed';
        $delay = min(86400, 3600 * (2 ** min(5, $entry['attempts'] - 1)));
        $entry['next_attempt'] = gmdate('c', time() + $delay);
    }

    $state['links'][$key] = $entry;
}

function rsAppendManifest(string $file, array $link, array $result): void
{
    if ($file === '') {
        return;
    }

    $isNew = !is_file($file) || filesize($file) === 0;
    $handle = fopen($file, 'a');
    if ($handle === false) {
        fwrite(STDERR, "Cannot write manifest: $file\n");
        return;
    }

    if ($isNew) {
        fputcsv($handle, ['downloaded_at', 'title', 'type', 'url', 'files', 'comment_url'], "\t", '"', '');
    }
    fputcsv($handle, [
        gmdate('c'),
        rsCleanLine((string) ($link['title'] ?? '')),
        (string) ($link['type'] ?? ''),
        (string) ($link['url'] ?? ''),
        implode('; ', $result['files'] ?? []),
        (string) ($link['comment_url'] ?? ''),
    ], "\t", '"', '');
    fclose($handle);
}

function rsFindMdrs(string $configured): string
{
    $candidates = [];
    if ($configured !== '') {
        $candidates[] = $configured;
    }
    $candidates[] = __DIR__ . '/others/mediafire_rs-main/mdrs';
    $candidates[] = 'mdrs';

    foreach ($candidates as $candidate) {
        $resolved = rsResolveExecutable($candidate);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    return '';
}

function rsResolveExecutable(string $candidate): string
{
    if ($candidate === '') {
        return '';
    }

    if (str_contains($candidate, '/')) {
        return (is_file($candidate) && is_executable($candidate)) ? $candidate : '';
    }

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir === '') {
            continue;
        }
        $path = rtrim($dir, '/') . '/' . $candidate;
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function rsRunProcess(array $cmd, int $timeout): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [127, '', 'cannot start command', false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timedOut = false;
    $exit = -1;

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) - $start > $timeout) {
            $timedOut = true;
            proc_terminate($process, 9);
            usleep(200000);
            break;
        }

        usleep(100000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);

    if ($exit < 0 && $closeCode >= 0) {
        $exit = $closeCode;
    }

    return [$exit, $stdout, $stderr, $timedOut];
}

function rsLinkLabel(array $link): string
{
    $type = $link['type'] ?? 'link';
    if ($type === 'mediafire' || $type === 'mediafire-folder') {
        $key = $link['key'] ?? '';
        if (preg_match('~mediafire:(?:file|folder):(.+)$~', $key, $m)) {
            return 'mediafire:' . $m[1];
        }
    }
    if ($type === 'gdrive' && !empty($link['gdrive_id'])) {
        return 'gdrive:' . $link['gdrive_id'];
    }
    return $type;
}

function rsRelativePath(string $dir, string $path): string
{
    $dir = rtrim($dir, '/') . '/';
    return str_starts_with($path, $dir) ? substr($path, strlen($dir)) : $path;
}

function rsCleanLine(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~\s+~u', ' ', $text);
    return trim((string) $text);
}

function rsFeedUserAgent(array $options): string
{
    if (!empty($options['user-agent'])) {
        return $options['user-agent'];
    }

    // Reddit rejects browser-like User-Agents that are not real browsers
    // (it serves a "blocked by network security" page).  A simple
    // application-style agent works.
    return 'booktools/1.0';
}

function rsUserAgent(array $options): string
{
    if (!empty($options['user-agent'])) {
        return $options['user-agent'];
    }

    // Browser-like for publisher/Google Drive PDF endpoints.
    return 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0'
        . ' booktools-reddit-scholar/1.0';
}

function rsLog(array $options, string $message): void
{
    if ($options['verbose']
        || str_starts_with($message, 'Feed:')
        || str_starts_with($message, 'Output:')
        || str_starts_with($message, 'State:')
        || str_contains($message, 'waiting')
        || str_contains($message, 'No new links')) {
        echo $message . "\n";
    }
}
