<?php

// Crawl calibre content-server

require_once('lib.php');

$base = $argv[1];

$allowed_formats = ['pdf', 'fb2', 'epub', 'lit', 'mobi', 'djvu', 'djv', 'txt', 'rtf', 'azw', 'azw3', 'cbz'];
$dir = getSiteDirectory($base);

$offset = 0;
$total = 1;

while ($offset < $total) {
    $url = "http://$base/xml?start=$offset&num=100&sort=date&order=descending";
    $response = httpGet($url);
    $books = new SimpleXMLElement($response);
    $total = $books['total'];

    foreach ($books->book as $metadata) {
        $offset += 1;

        $id = $metadata['id'];
        $isbn = $metadata['isbn'];

        echo "$offset/$total $metadata[title]\n";

        if (str_contains($metadata['title'], 'z-lib')) continue;

        try {
            if (isValidISBN($isbn)) {
                if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                    continue;
                }
            } else {
                continue;
                $search = cleanupSearchQuery($metadata['title'] . ' '. $metadata['author_sort']);
                if (inAnnasArchive($search)) continue;
            }
        } catch (Exception $e) {
            continue;
        }

        $formats = explode(';', $metadata['formats']);
        foreach ($formats as $format) {
            $ext = strtolower($format);
            if (!in_array($ext, $allowed_formats)) continue;

            $filename = createFileName($id, $isbn, $metadata['title'], $metadata['authors'], $ext);
            $url = "http://$base/get/$ext/get_$id.$ext";

            if (file_exists("$dir/$filename")) continue;

            echo "Downloading $url to $filename...";

            try {
                $contents = httpGet($url);
                file_put_contents("$dir/$filename", $contents);
                echo " success.\n";
            } catch (Exception $e) {
                echo " failed: $e\n";
            }
        }
    }
}
