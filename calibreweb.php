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

        foreach ($isbns as $isbn) {
            if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                continue 2;
            }
        }

        $dom = parseHtml($html);
        $title = $dom->querySelector('h2#title')->textContent;
        $author = $dom->querySelector('p.author a:first-child')->textContent;

        $search = "$title $author";
        $search = preg_replace('~[0-9-]+~', '', $search);
        if (str_contains($title, 'z-lib') || inAnnasArchive($search)) {
            continue;
        }

        $exts = [];
        $possible_exts = ['pdf', 'epub', 'txt'];
        foreach ($possible_exts as $ext) {
            if (str_contains($html, $ext)) {
                $exts[] = $ext;
            }
        }

        $isbn = $isbns[0] ?? '';
        foreach ($exts as $ext) {
            $filename = "$id $isbn $title - $author.$ext";
            if (strlen($filename) > 250) {
                $filename = "$id $isbn $title.$ext";
            }
            $filepath = "$basedir/$filename";
            

            $download_urls = [
                "$base/show/$id/$ext",
                "$base/download/$id/$ext/$id.$ext",
            ];
            foreach ($download_urls as $download_url) {
                echo "Downloading $download_url to $filepath...";
                try {
                    $saved = httpSave($download_url, null, ['X-Requested-With: XMLHttpRequest']);
                    link($saved, $filepath);
                    echo " success.\n";
                    $errors = 0;
                    break;
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
