<?php
require_once('lib.php');

$url_base = rtrim($argv[1], '/');
echo "$url_base\n";

$dir = getSiteDirectory(parse_url($url_base, PHP_URL_HOST));
$existing = glob("$dir/*");
if (!empty($existing)) {
    echo "Already downloaded.\n";
    // return;
}

$recent_url = "$url_base/api/recent";
$json = httpGet($recent_url);
$data = json_decode($json, true);
$ids = [];
foreach ($data['books'] as $book) {
    $ids[] = $book['id'];
}
$highest_id = max($ids);

for ($i = 0; $i < $highest_id; $i++) {
    echo "\n$i/$highest_id ";
    $book_url = "$url_base/api/book/$i";
    $json = httpGet($book_url);
    $data = json_decode($json, true);
    if ($data === null || $data['err'] !== 'ok') {
        echo "failed.";
        continue;
    }
    $metadata = $data['book'];
    $isbn = $metadata['isbn'] ?? '';

    if (!isValidISBN($isbn)) continue;

    if (str_contains($metadata['title'], 'z-lib')) continue;
    if (str_contains($metadata['title'], 'z_lib')) continue;
    if (str_contains($metadata['title'], 'Z_Lib')) continue;
    if (str_contains($metadata['title'], 'Audiobook')) continue;

    $requested = isValidISBN($isbn) && isRequested($isbn);

    if (!$requested)
    try {
        if (isValidISBN($isbn)) {
            if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                echo "ISBN already present.";
                continue;
            }
        }
        
        $search = cleanupSearchQuery($metadata['title'] . ' '. $metadata['author_sort']);
        if (inAnnasArchive($search)) {
            echo "title already present.";
            continue;
        }
    } catch (Exception $e) {
        // pass
    }

    foreach ($metadata['files'] as $file) {
        $href = parse_url($file['href'], PHP_URL_PATH) ?? $file['href'];
        $url = $url_base . $href;
        $ext = strtolower($file['format']);
        $filename = createFileName($i, $isbn, $metadata['title'], $metadata['authors'], $ext);
        echo "Downloading $filename...\n";

        if (file_exists("$dir/$filename") && filesize("$dir/$filename") == $file['size']) {
            echo " file exists.";
            continue;
        }

        $saved_path = httpSave($url);

        rename($saved_path, "$dir/$filename");
    }
}
echo "\n";
touch("$dir/talebook_processed.txt");
