<?php

// Crawl calibre-web site

require_once('lib.php');

$base = $argv[1];

$id = 0;
$errors = 0;
while (true) {
    echo "$id\r";
    $id += 1;
    sleep(1);
    try {
        $path = httpSave("$base/book/$id");
        $basedir = dirname($path);
        $html = file_get_contents($path);
        $isbns = grepIsbns($html);
        if (empty($isbns)) continue;

        foreach ($isbns as $isbn) {
            if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                continue 2;
            }
        }

        $dom = parseHtml($html);
        $title = $dom->querySelector('h2')->textContent;
        $author = $dom->querySelector('p.author a:first-child')->textContent;

        $search = cleanupSearchQuery("$title $author");
        if (str_contains($title, 'z-lib') || inAnnasArchive($search)) {
            continue;
        }

        $exts = [];
        $possible_exts = ['epub', 'pdf', 'azw', 'azw3', 'mobi', 'cbz'];
        foreach ($possible_exts as $ext) {
            if (preg_match("~\b$ext\b~", $html)) {
                $exts[] = $ext;
            }
        }

        $isbn = $isbns[0] ?? '';
        foreach ($exts as $ext) {
            $filename = createFileName($id, $isbn, $title, $author, $ext);
            $filepath = "$basedir/$filename";
            

            $download_urls = [
                "$base/download/$id/$ext/$id.$ext",
                "$base/show/$id/$ext",
            ];
            foreach ($download_urls as $download_url) {
                echo "Downloading $download_url to $filepath...";
                try {
                    $contents = httpGet($download_url, null, ['X-Requested-With: XMLHttpRequest']);
                    
                    if (empty($contents) || strlen($contents) < 10000) {
                        throw new DownloadException($contents);
                    }

                    atomic_file_put_contents($filepath, $contents);
                    echo " success.\n";
                    $errors = 0;
                    break 2;
                } catch (DownloadException $e) {
                    echo " failed: $e\n";
                    $errors += 1;
                }
            }
        }
    } catch (Exception $e) {
        echo "$e\n";
        $errors += 1;
    }
    if ($errors > 50) break;
}
echo "\n";
