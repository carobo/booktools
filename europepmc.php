<?php
/**
 * Download recent open-access article PDFs from Europe PMC.
 *
 * Why not use the RSS feed?  The RSS endpoint is behind Cloudflare and its
 * date sorting is based on the publication date.  Europe PMC contains many
 * ahead-of-print records whose publication date is in the future (for example
 * "2027-01-01").  This script uses the REST search API and the FIRST_IDATE
 * field instead: the date on which the record was first indexed by Europe PMC.
 * That date is stable and cannot be in the future, and it lets the script pick
 * up new additions without downloading the whole archive.
 *
 * Examples:
 *
 *   # Download up to 100 PDFs indexed in the last 7 days
 *   php europepmc.php
 *
 *   # Only articles matching your topic
 *   php europepmc.php --query 'HAS_PDF:y AND malaria'
 *
 *   # Show what would be downloaded, without writing any files
 *   php europepmc.php --list --days 30 --max 20
 *
 *   # Cron example: every day at 06:00
 *   0 6 * * * cd /path/to/booktools && php europepmc.php --max 100
 *
 * The script keeps its progress in .europepmc-state.json inside the output
 * directory.  Records that were already handled are skipped on later runs.
 *
 * For every downloaded PDF, a sidecar file with the same basename and the
 * extension .txt.gz is written.  It contains the text of the first page
 * extracted with pdftotext, followed by the JSON of the full Europe PMC
 * record.
 */

const EUROPEPMC_SEARCH_URL = 'https://www.ebi.ac.uk/europepmc/webservices/rest/search';

$options = epmcParseOptions($argv);

if ($options['help']) {
    epmcPrintHelp();
    exit(0);
}

$outDir = rtrim($options['out'], '/');
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: $outDir\n");
    exit(1);
}

$stateFile = $options['state'] ?: "$outDir/.europepmc-state.json";
$state = epmcLoadState($stateFile);

$lock = epmcAcquireLock("$stateFile.lock");
if (!$lock) {
    fwrite(STDERR, "Another europepmc.php run is already using $stateFile\n");
    exit(1);
}

if ($options['backfill']) {
    epmcBackfillSidecars($options, $outDir, $state);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$until = $options['until'] ?: gmdate('Y-m-d');

if ($options['since']) {
    $since = $options['since'];
} elseif (!empty($state['lastIndexDate'])) {
    // Overlap a little so records indexed late in the day are not missed.
    $since = gmdate('Y-m-d', strtotime($state['lastIndexDate'] . " -{$options['lookback']} days"));
} else {
    $since = gmdate('Y-m-d', strtotime("$until -{$options['days']} days"));
}

if (strtotime($since) === false || strtotime($until) === false || $since > $until) {
    fwrite(STDERR, "Invalid date range: $since .. $until\n");
    exit(1);
}

$query = '(' . $options['query'] . ') AND FIRST_IDATE:[' . $since . ' TO ' . $until . ']';

epmcLog($options, "Searching Europe PMC: $query");
epmcLog($options, "Output: $outDir");
epmcLog($options, "State:  $stateFile");
epmcLog($options, "Range:  $since .. $until");

$stats = ['seen' => 0, 'downloaded' => 0, 'failed' => 0, 'listed' => 0];

// Retry a few previously failed records first.  They are not part of the
// normal date query any more, so keeping a small failure list is useful.
epmcRetryFailed($options, $state, $stateFile, $outDir, $stats);

$cursor = '*';
$stop = false;

while (!$stop) {
    $params = [
        'query' => $query,
        'format' => 'json',
        'resultType' => 'core',
        'pageSize' => 100,
        // FIRST_IDATE_D is the sortable form of FIRST_IDATE.  Ascending order
        // means a partially completed run continues where it stopped instead
        // of starting again at the newest article.
        'sort' => 'FIRST_IDATE_D asc',
        'cursorMark' => $cursor,
    ];

    $url = EUROPEPMC_SEARCH_URL . '?' . http_build_query($params);
    $data = epmcGetJson($url, $options);

    if ($data === null) {
        epmcLog($options, 'Search request failed, stopping this run.');
        break;
    }

    $results = $data['resultList']['result'] ?? [];
    if (!$results) {
        epmcLog($options, 'No results in this date range.');
        break;
    }

    foreach ($results as $record) {
        $key = epmcRecordKey($record);
        if ($key === null) {
            continue;
        }

        if (isset($state['seen'][$key])) {
            $stats['seen']++;
            epmcAdvanceStateDate($state, $record);
            continue;
        }

        if (isset($state['failed'][$key])) {
            // Failed records are retried separately by epmcRetryFailed().
            $stats['seen']++;
            epmcAdvanceStateDate($state, $record);
            continue;
        }

        if ($options['max'] > 0 && ($stats['downloaded'] + $stats['failed'] + $stats['listed']) >= $options['max']) {
            $stop = true;
            break;
        }

        $result = epmcHandleRecord($record, $options, $state, $stateFile, $outDir);
        $stats[$result]++;
        epmcAdvanceStateDate($state, $record);

        if ($options['list'] || $options['dry-run']) {
            // Do not grow the state file during a read-only run.
            continue;
        }

        epmcSaveState($stateFile, $state);

        if ($stop) {
            break;
        }
    }

    if ($stop) {
        break;
    }

    $next = $data['nextCursorMark'] ?? null;
    if (empty($next) || $next === $cursor) {
        break;
    }
    $cursor = $next;

    // Be polite to the API.
    usleep(250000);
}

if (!$options['list'] && !$options['dry-run']) {
    $state['lastRun'] = gmdate('c');
    epmcSaveState($stateFile, $state);
}

epmcLog($options, sprintf(
    'Done: %d downloaded, %d failed, %d already seen, %d listed.',
    $stats['downloaded'],
    $stats['failed'],
    $stats['seen'],
    $stats['listed']
));

flock($lock, LOCK_UN);
fclose($lock);


function epmcParseOptions(array $argv): array
{
    $defaults = [
        'query' => 'HAS_PDF:y',
        'days' => 7,
        'since' => '',
        'until' => '',
        'lookback' => 1,
        'max' => 100,
        'out' => 'europepmc-pdfs',
        'state' => '',
        'email' => '',
        'cookie' => '',
        'user-agent' => '',
        'retry-failed' => false,
        'backfill' => false,
        'dry-run' => false,
        'list' => false,
        'verbose' => false,
        'help' => false,
        'max-set' => false,
    ];

    $long = [
        'query:', 'days:', 'since:', 'until:', 'lookback:', 'max:', 'out:',
        'state:', 'email:', 'cookie:', 'user-agent:', 'retry-failed', 'backfill',
        'dry-run', 'list', 'verbose', 'help',
    ];

    $parsed = getopt('', $long);
    if ($parsed === false) {
        $parsed = [];
    }

    $opts = $defaults;
    $opts['max-set'] = isset($parsed['max']);
    foreach ($parsed as $name => $value) {
        $name = str_replace('_', '-', $name);
        if (array_key_exists($name, $opts)) {
            $opts[$name] = is_bool($opts[$name]) ? true : $value;
        }
    }

    foreach (['days', 'lookback', 'max'] as $name) {
        $opts[$name] = (int) $opts[$name];
    }
    if ($opts['max'] < 0) {
        $opts['max'] = 0;
    }
    if ($opts['lookback'] < 0) {
        $opts['lookback'] = 0;
    }

    return $opts;
}

function epmcPrintHelp(): void
{
    echo <<<HELP
Usage: php europepmc.php [options]

Downloads recent open-access PDFs from Europe PMC using the REST API.  New
records are detected with FIRST_IDATE (the Europe PMC indexing date), not with
the publication date, so ahead-of-print articles dated in the future are not a
problem.

Options:
  --query QUERY       Europe PMC query (default: HAS_PDF:y)
  --days N            On the first run, look back N days (default: 7)
  --since YYYY-MM-DD  Explicit first indexing date
  --until YYYY-MM-DD  Explicit last indexing date (default: today)
  --lookback N        Overlap days between runs (default: 1)
  --max N             Maximum records to handle per run, 0 = no limit (default: 100)
  --out DIR           Output directory (default: europepmc-pdfs)
  --state FILE        State file (default: DIR/.europepmc-state.json)
  --email ADDRESS     Contact e-mail (also enables the Unpaywall fallback)
  --cookie STRING     Optional Cookie header for Cloudflare-protected downloads
  --user-agent STRING Override the HTTP User-Agent
  --retry-failed      Retry records that previously failed
  --backfill          Write/update sidecars for PDFs already in the output directory
                      (--max N limits the number processed; default all)
  --list              List what would be downloaded; do not download or save state
  --dry-run           Same as --list
  --verbose           Print extra diagnostics
  --help              Show this help

PDF sources tried for each record:
  1. Europe PMC / publisher open-access PDF URLs from the search result
  2. https://europepmc.org/articles/PMC...?pdf=render
  3. https://europepmc.org/api/getPdf?pmcid=PMC...
  4. The PDF URL returned by the Europe PMC fulltextRepo metadata endpoint
  5. The open-access PDF ZIP from https://ftp.ebi.ac.uk/pub/databases/pmc/pdf/OA/
  6. Unpaywall, if --email was supplied

For each downloaded paper, a gzip-compressed sidecar with the same basename
and the extension .txt.gz is written next to the PDF.  It contains the first
page of text (extracted with pdftotext) followed by the record as JSON.

HELP;
}

function epmcLoadState(string $file): array
{
    if (!is_file($file)) {
        return ['version' => 1, 'lastIndexDate' => null, 'seen' => [], 'failed' => []];
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Warning: cannot parse state file $file; starting with an empty state.\n");
        return ['version' => 1, 'lastIndexDate' => null, 'seen' => [], 'failed' => []];
    }

    $data += ['version' => 1, 'lastIndexDate' => null, 'seen' => [], 'failed' => []];
    if (!is_array($data['seen'])) {
        $data['seen'] = [];
    }
    if (!is_array($data['failed'])) {
        $data['failed'] = [];
    }
    return $data;
}

function epmcSaveState(string $file, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Cannot encode state: " . json_last_error_msg() . "\n");
        return;
    }

    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        fwrite(STDERR, "Cannot write state file: $file\n");
    }
}

function epmcAcquireLock(string $file)
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

function epmcAdvanceStateDate(array &$state, array $record): void
{
    $date = $record['firstIndexDate'] ?? '';
    if ($date !== '' && ($state['lastIndexDate'] === null || $date > $state['lastIndexDate'])) {
        $state['lastIndexDate'] = $date;
    }
}

function epmcRecordKey(array $record): ?string
{
    if (!empty($record['pmcid'])) {
        return (string) $record['pmcid'];
    }
    if (!empty($record['pmid'])) {
        return 'PMID' . $record['pmid'];
    }
    if (!empty($record['doi'])) {
        return 'DOI:' . strtolower($record['doi']);
    }
    if (!empty($record['id'])) {
        return 'EPMC:' . $record['id'];
    }
    return null;
}

function epmcHandleRecord(array $record, array $options, array &$state, string $stateFile, string $outDir): string
{
    $key = epmcRecordKey($record);
    if ($key === null) {
        return 'failed';
    }

    $file = $outDir . '/' . epmcFileName($record);
    $title = $record['title'] ?? '(no title)';

    if (is_file($file) && epmcIsPdf($file)) {
        epmcLog($options, "Already present: $file");
        if (!is_file(epmcSidecarPath($file))) {
            epmcWriteSidecar($file, $record, $options);
        }
        $state['seen'][$key] = ['file' => $file, 'at' => gmdate('c')];
        unset($state['failed'][$key]);
        return 'seen';
    }

    $urls = epmcDirectPdfUrls($record);
    if ($options['list'] || $options['dry-run']) {
        echo "[$key] $title\n";
        foreach ($urls as $url) {
            echo "    $url\n";
        }
        if (!$urls) {
            echo "    (no direct PDF URL; fulltextRepo/FTP fallback would be tried)\n";
        }
        return 'listed';
    }

    epmcLog($options, "[$key] $title");

    $errors = [];
    foreach ($urls as $url) {
        $error = epmcDownloadPdf($url, $file, $options);
        if ($error === null) {
            epmcWriteSidecar($file, $record, $options);
            epmcRecordSuccess($state, $key, $file, $record);
            echo "  downloaded via $url\n";
            return 'downloaded';
        }
        $errors[] = "$url: $error";
    }

    foreach (epmcFallbackPdfSources($record, $options) as $source) {
        if ($source['type'] === 'zip') {
            $error = epmcDownloadZipPdf($source['url'], $file, $record, $options);
        } else {
            $error = epmcDownloadPdf($source['url'], $file, $options);
        }

        if ($error === null) {
            epmcWriteSidecar($file, $record, $options);
            epmcRecordSuccess($state, $key, $file, $record);
            echo "  downloaded via {$source['url']}\n";
            return 'downloaded';
        }
        $errors[] = "{$source['url']}: $error";
    }

    $state['failed'][$key] = [
        'record' => epmcCompactRecord($record),
        'attempts' => (int) ($state['failed'][$key]['attempts'] ?? 0) + 1,
        'lastAttempt' => gmdate('c'),
        'nextAttempt' => gmdate('c', time() + 86400 * min(7, (int) ($state['failed'][$key]['attempts'] ?? 0) + 1)),
        'error' => implode(' | ', array_slice($errors, 0, 4)),
    ];

    echo "  FAILED: " . implode(' | ', array_slice($errors, 0, 4)) . "\n";
    return 'failed';
}

function epmcRecordSuccess(array &$state, string $key, string $file, array $record): void
{
    $state['seen'][$key] = [
        'file' => $file,
        'title' => $record['title'] ?? '',
        'doi' => $record['doi'] ?? '',
        'at' => gmdate('c'),
    ];
    unset($state['failed'][$key]);
}

function epmcSidecarPath(string $pdfFile): string
{
    return preg_replace('~\.pdf$~i', '', $pdfFile) . '.txt.gz';
}

/**
 * Write a gzip-compressed sidecar next to the PDF.  Like booktools.php's
 * fileToText()/pdfToText() convention, the text lives in a .txt.gz file, but
 * here it is the first page only and is followed by the full record as JSON.
 */
function epmcWriteSidecar(string $pdfFile, array $record, array $options): void
{
    $target = epmcSidecarPath($pdfFile);
    $text = epmcFirstPageText($pdfFile);

    $content = $text;
    if ($content !== '' && !str_ends_with($content, "\n")) {
        $content .= "\n";
    }
    if ($content !== '') {
        $content .= "\n";
    }
    $content .= epmcRecordJson($record) . "\n";

    epmcWriteGzipFile($target, $content, $options);
}

function epmcRecordJson(array $record): string
{
    $json = json_encode($record);
    if ($json === false) {
        $json = json_encode(epmcCompactRecord($record)) ?: '{}';
    }
    return $json;
}

function epmcWriteGzipFile(string $target, string $content, array $options): bool
{
    $compressed = gzencode($content);
    if ($compressed === false) {
        epmcLog($options, "Warning: cannot gzip $target");
        return false;
    }

    $tmp = $target . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $compressed) === false || !rename($tmp, $target)) {
        @unlink($tmp);
        epmcLog($options, "Warning: cannot write $target");
        return false;
    }

    epmcLog($options, "Wrote $target");
    return true;
}

function epmcFirstPageText(string $pdfFile): string
{
    // pdftotext is the same tool used by pdfToText() in booktools.php.
    $cmd = 'pdftotext -f 1 -l 1 -nodiag ' . escapeshellarg($pdfFile) . ' - 2>/dev/null';
    $text = @shell_exec($cmd);
    if (!is_string($text)) {
        return '';
    }

    // Keep the sidecar small even if the first page contains a lot of text.
    if (strlen($text) > 65536) {
        $text = mb_strcut($text, 0, 65536, 'UTF-8');
    }

    return trim($text);
}

/**
 * Retroactively add record JSON to sidecars of PDFs that were downloaded
 * before sidecars were introduced.  Old text-only .txt.gz files keep their
 * text and get the JSON appended; missing sidecars are created from the PDF.
 */
function epmcBackfillSidecars(array $options, string $outDir, array $state): void
{
    $limit = $options['max-set'] ? $options['max'] : 0;

    $keysByBase = [];
    foreach ($state['seen'] ?? [] as $key => $entry) {
        if (!empty($entry['file'])) {
            $keysByBase[basename($entry['file'])] = (string) $key;
        }
    }

    $names = @scandir($outDir);
    if (!is_array($names)) {
        fwrite(STDERR, "Cannot read output directory: $outDir\n");
        return;
    }

    $pdfs = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $outDir . '/' . $name;
        if (is_file($path) && preg_match('~\.pdf$~i', $name)) {
            $pdfs[] = $path;
        }
    }
    sort($pdfs);

    $pending = [];
    $complete = 0;
    foreach ($pdfs as $pdf) {
        if (!epmcIsPdf($pdf)) {
            continue;
        }

        $sidecar = epmcSidecarPath($pdf);
        if (is_file($sidecar) && epmcSidecarContainsRecord($sidecar)) {
            $complete++;
            continue;
        }

        $key = $keysByBase[basename($pdf)] ?? epmcKeyFromFileName(basename($pdf));
        $pending[] = [
            'pdf' => $pdf,
            'sidecar' => $sidecar,
            'key' => $key,
            'append' => is_file($sidecar),
        ];
    }

    if ($limit > 0) {
        $pending = array_slice($pending, 0, $limit);
    }

    echo sprintf(
        "Backfill: %d PDFs found, %d already complete, %d to process.\n",
        count($pdfs),
        $complete,
        count($pending)
    );

    if (!$pending) {
        return;
    }

    $byKey = [];
    $noKey = 0;
    foreach ($pending as $item) {
        if ($item['key'] === null) {
            $noKey++;
            continue;
        }
        $byKey[$item['key']][] = $item;
    }

    $records = [];
    $keys = array_keys($byKey);
    foreach (array_chunk($keys, 50) as $chunk) {
        $query = implode(' OR ', array_map('epmcKeyQuery', $chunk));
        $url = EUROPEPMC_SEARCH_URL . '?' . http_build_query([
            'query' => $query,
            'format' => 'json',
            'resultType' => 'core',
            'pageSize' => 100,
        ]);

        $data = epmcGetJson($url, $options);
        if ($data !== null) {
            foreach ($data['resultList']['result'] ?? [] as $record) {
                $key = epmcRecordKey($record);
                if ($key !== null) {
                    $records[$key] = $record;
                }
            }
        } else {
            epmcLog($options, 'Cannot fetch records for a backfill batch.');
        }

        echo sprintf("  fetched records for %d/%d keys\n", count($records), count($keys));
        usleep(1000000);
    }

    $written = 0;
    $missing = $noKey;
    foreach ($byKey as $key => $items) {
        if (!isset($records[$key])) {
            $missing += count($items);
            continue;
        }

        foreach ($items as $item) {
            if ($item['append']) {
                epmcAppendSidecarRecord($item['sidecar'], $records[$key], $options);
            } else {
                epmcWriteSidecar($item['pdf'], $records[$key], $options);
            }
            $written++;
        }
    }

    echo sprintf(
        "Backfill done: %d sidecars written, %d skipped (record not found).\n",
        $written,
        $missing
    );
}

function epmcSidecarContainsRecord(string $sidecar): bool
{
    $data = @gzdecode((string) @file_get_contents($sidecar));
    if (!is_string($data)) {
        return false;
    }

    $data = rtrim($data);
    if ($data === '') {
        return false;
    }

    $pos = strrpos($data, "\n");
    $last = $pos === false ? $data : substr($data, $pos + 1);
    return is_array(json_decode($last, true));
}

function epmcAppendSidecarRecord(string $sidecar, array $record, array $options): void
{
    $data = @gzdecode((string) @file_get_contents($sidecar));
    if (!is_string($data)) {
        // Unreadable sidecar: rebuild it from the PDF instead.
        epmcWriteSidecar(preg_replace('~\.txt\.gz$~i', '.pdf', $sidecar), $record, $options);
        return;
    }

    $content = rtrim($data) . "\n\n" . epmcRecordJson($record) . "\n";
    epmcWriteGzipFile($sidecar, $content, $options);
}

function epmcKeyFromFileName(string $name): ?string
{
    if (preg_match('~^(PMC\d+)~', $name, $m)) {
        return $m[1];
    }
    if (preg_match('~^PMID(\d+)~', $name, $m)) {
        return 'PMID' . $m[1];
    }
    if (preg_match('~^DOI (.+?)(?: - |\.pdf$)~i', $name, $m)) {
        return 'DOI:' . strtolower(trim($m[1]));
    }
    if (preg_match('~^EPMC (.+?)(?: - |\.pdf$)~i', $name, $m)) {
        return 'EPMC:' . trim($m[1]);
    }
    return null;
}

function epmcKeyQuery(string $key): string
{
    if (str_starts_with($key, 'PMC')) {
        return '(PMCID:' . $key . ')';
    }
    if (str_starts_with($key, 'PMID')) {
        return '(EXT_ID:' . substr($key, 4) . ' AND SRC:MED)';
    }
    if (str_starts_with($key, 'DOI:')) {
        $doi = str_replace(['\\', '"'], ['\\\\', '\\"'], substr($key, 4));
        return '(DOI:"' . $doi . '")';
    }
    if (str_starts_with($key, 'EPMC:')) {
        return '(EXT_ID:' . substr($key, 5) . ')';
    }
    return '(' . $key . ')';
}

function epmcRetryFailed(array $options, array &$state, string $stateFile, string $outDir, array &$stats): void
{
    if ($options['list'] || $options['dry-run'] || empty($state['failed'])) {
        return;
    }

    foreach ($state['failed'] as $key => $failure) {
        if (!$options['retry-failed']) {
            $next = strtotime($failure['nextAttempt'] ?? '') ?: 0;
            if ($next > time()) {
                continue;
            }
            if ((int) ($failure['attempts'] ?? 0) >= 5) {
                continue;
            }
        }

        if ($options['max'] > 0 && ($stats['downloaded'] + $stats['failed'] + $stats['listed']) >= $options['max']) {
            break;
        }

        $record = $failure['record'] ?? null;
        if (!is_array($record) || !epmcRecordKey($record)) {
            unset($state['failed'][$key]);
            continue;
        }

        epmcLog($options, "Retrying failed record $key");
        $result = epmcHandleRecord($record, $options, $state, $stateFile, $outDir);
        $stats[$result]++;
        epmcSaveState($stateFile, $state);
    }
}

function epmcDirectPdfUrls(array $record): array
{
    $urls = [];

    if (!empty($record['pmcid'])) {
        // These are the two direct PDF endpoints used by the Europe PMC web
        // interface.  The first one currently redirects to the second.
        $pmcid = rawurlencode($record['pmcid']);
        $urls[] = 'https://europepmc.org/articles/' . $pmcid . '?pdf=render';
        $urls[] = 'https://europepmc.org/api/getPdf?pmcid=' . $pmcid;
    }

    foreach ($record['fullTextUrlList']['fullTextUrl'] ?? [] as $entry) {
        if (($entry['documentStyle'] ?? '') !== 'pdf') {
            continue;
        }
        if (($entry['availabilityCode'] ?? '') !== 'OA') {
            continue;
        }
        if (!empty($entry['url'])) {
            $urls[] = $entry['url'];
        }
    }

    return array_values(array_unique($urls));
}

function epmcFallbackPdfSources(array $record, array $options): array
{
    $sources = [];
    $pmcid = $record['pmcid'] ?? '';

    if ($pmcid) {
        $metadata = epmcGetJson(
            'https://europepmc.org/api/fulltextRepo?pmcId=' . rawurlencode($pmcid) . '&type=METADATA',
            $options
        );

        if (is_array($metadata)) {
            foreach ($metadata['files'] ?? [] as $file) {
                if (($file['type'] ?? '') === 'pdf' && !empty($file['url'])) {
                    $sources[] = ['url' => $file['url'], 'type' => 'pdf'];
                }
            }
        }

        $sources[] = ['url' => epmcFtpZipUrl($pmcid), 'type' => 'zip'];
    }

    if (!empty($record['doi']) && !empty($options['email'])) {
        foreach (epmcUnpaywallPdfUrls($record['doi'], $options) as $url) {
            $sources[] = ['url' => $url, 'type' => 'pdf'];
        }
    }

    // Keep the order but remove duplicates.
    $seen = [];
    $unique = [];
    foreach ($sources as $source) {
        if (!isset($seen[$source['url']])) {
            $seen[$source['url']] = true;
            $unique[] = $source;
        }
    }
    return $unique;
}

function epmcFtpZipUrl(string $pmcid): string
{
    $id = preg_replace('~\D~', '', $pmcid);
    // The FTP archive uses blocks of 10,000 PMC IDs.  Examples:
    //   PMC1369286  -> PMCxxxx137/PMC1369286.zip
    //   PMC3258128  -> PMCxxxx326/PMC3258128.zip
    //   PMC13696841 -> PMCxxxx1370/PMC13696841.zip (once the monthly dump catches up)
    $block = (int) ceil(((int) $id) / 10000);
    $dir = str_pad((string) $block, 3, '0', STR_PAD_LEFT);

    return "https://ftp.ebi.ac.uk/pub/databases/pmc/pdf/OA/PMCxxxx$dir/PMC$id.zip";
}

function epmcUnpaywallPdfUrls(string $doi, array $options): array
{
    $url = 'https://api.unpaywall.org/v2/' . rawurlencode($doi)
        . '?email=' . rawurlencode($options['email']);
    $data = epmcGetJson($url, $options);
    if (!is_array($data)) {
        return [];
    }

    $urls = [];
    if (!empty($data['best_oa_location']['url_for_pdf'])) {
        $urls[] = $data['best_oa_location']['url_for_pdf'];
    }
    foreach ($data['oa_locations'] ?? [] as $location) {
        if (!empty($location['url_for_pdf'])) {
            $urls[] = $location['url_for_pdf'];
        }
    }

    return array_values(array_unique($urls));
}

function epmcDownloadPdf(string $url, string $target, array $options): ?string
{
    $tmp = $target . '.part.' . getmypid();
    [$ok, $error] = epmcDownloadFile($url, $tmp, $options);

    if (!$ok) {
        @unlink($tmp);
        return $error;
    }

    if (!epmcIsPdf($tmp)) {
        @unlink($tmp);
        return 'response is not a PDF (probably an HTML login/Cloudflare page)';
    }

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        return "cannot move downloaded file to $target";
    }

    return null;
}

function epmcDownloadZipPdf(string $url, string $target, array $record, array $options): ?string
{
    $zipFile = $target . '.zip.' . getmypid();
    [$ok, $error] = epmcDownloadFile($url, $zipFile, $options);

    if (!$ok) {
        @unlink($zipFile);
        return $error;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        @unlink($zipFile);
        return 'downloaded file is not a readable ZIP archive';
    }

    $best = null;
    $bestScore = -1;
    $pmcNumber = preg_replace('~\D~', '', $record['pmcid'] ?? '');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || !preg_match('~\.pdf$~i', $name)) {
            continue;
        }

        $stat = $zip->statIndex($i);
        $size = (int) ($stat['size'] ?? 0);
        $score = $size;
        if ($pmcNumber !== '' && stripos(basename($name), "PMC$pmcNumber") === 0) {
            $score += 1000000000;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $name;
        }
    }

    if ($best === null) {
        $zip->close();
        @unlink($zipFile);
        return 'ZIP archive contains no PDF';
    }

    $pdfData = $zip->getFromName($best);
    $zip->close();
    @unlink($zipFile);

    if ($pdfData === false) {
        return "cannot read $best from ZIP archive";
    }

    $tmp = $target . '.part.' . getmypid();
    if (file_put_contents($tmp, $pdfData) === false) {
        return 'cannot write extracted PDF';
    }

    if (!epmcIsPdf($tmp)) {
        @unlink($tmp);
        return 'extracted ZIP member is not a valid PDF';
    }

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        return "cannot move extracted PDF to $target";
    }

    return null;
}

function epmcDownloadFile(string $url, string $target, array $options): array
{
    sleep(2);
    $handle = fopen($target, 'wb');
    if ($handle === false) {
        return [false, "cannot open $target for writing"];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => epmcUserAgent($options),
        CURLOPT_AUTOREFERER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/pdf,application/zip,application/octet-stream,*/*',
            'Accept-Language: en-US,en;q=0.9',
        ],
        CURLOPT_ENCODING => '',
    ]);

    if (!empty($options['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
    }

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($handle);

    if (!$ok || $status < 200 || $status >= 300) {
        $reason = $curlError ?: "HTTP $status";
        if ($contentType !== '') {
            $reason .= " ($contentType)";
        }
        return [false, $reason];
    }

    return [true, ''];
}

function epmcGetJson(string $url, array $options): ?array
{
    $attempts = 0;
    $maxAttempts = 3;

    while ($attempts < $maxAttempts) {
        $attempts++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => epmcUserAgent($options),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_ENCODING => '',
        ]);

        if (!empty($options['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status >= 200 && $status < 300) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $data;
            }
            epmcLog($options, "Invalid JSON from $url");
            return null;
        }

        if ($status === 429 || $status >= 500) {
            usleep(1000000 * $attempts);
            continue;
        }

        epmcLog($options, "Request failed ($status" . ($error ? ", $error" : '') . "): $url");
        return null;
    }

    epmcLog($options, "Request failed after $maxAttempts attempts: $url");
    return null;
}

function epmcUserAgent(array $options): string
{
    if (!empty($options['user-agent'])) {
        return $options['user-agent'];
    }

    // A browser-like agent is less likely to be blocked by publisher/Cloudflare
    // PDF endpoints, while the suffix identifies this tool.
    $contact = !empty($options['email']) ? '; mailto:' . $options['email'] : '';
    return 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0'
        . ' booktools-europepmc/1.0' . $contact;
}

function epmcFileName(array $record): string
{
    if (!empty($record['pmcid'])) {
        $prefix = $record['pmcid'];
    } elseif (!empty($record['pmid'])) {
        $prefix = 'PMID' . $record['pmid'];
    } elseif (!empty($record['doi'])) {
        $prefix = 'DOI ' . $record['doi'];
    } else {
        $prefix = 'EPMC ' . ($record['id'] ?? 'unknown');
    }

    $title = html_entity_decode((string) ($record['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = preg_replace('~\s+~u', ' ', $title);
    $title = trim($title);
    $title = mb_substr($title, 0, 120, 'UTF-8');
    $title = epmcFilenameCleanup($title);

    $name = $prefix . ($title !== '' ? ' - ' . $title : '');
    $name = mb_substr($name, 0, 230, 'UTF-8');

    return epmcFilenameCleanup($name) . '.pdf';
}

function epmcFilenameCleanup(string $name): string
{
    $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
    $name = preg_replace('/[\x00-\x1F\x7F<>"\/\\\\|?*]/u', ' ', $name);
    $name = preg_replace('~\s+~u', ' ', $name);
    return trim($name, ' .');
}

function epmcIsPdf(string $path): bool
{
    if (!is_file($path) || filesize($path) < 1024) {
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

function epmcCompactRecord(array $record): array
{
    return [
        'id' => $record['id'] ?? '',
        'source' => $record['source'] ?? '',
        'pmid' => $record['pmid'] ?? '',
        'pmcid' => $record['pmcid'] ?? '',
        'doi' => $record['doi'] ?? '',
        'title' => $record['title'] ?? '',
        'pubYear' => $record['pubYear'] ?? '',
        'firstIndexDate' => $record['firstIndexDate'] ?? '',
        'fullTextUrlList' => $record['fullTextUrlList'] ?? [],
    ];
}

function epmcLog(array $options, string $message): void
{
    if ($options['verbose'] || str_starts_with($message, 'Done:') || str_contains($message, 'failed')) {
        echo $message . "\n";
    }
}
