<?php

// Crawl calibre content-server

require_once('lib.php');

$base = $argv[1];

$allowed_formats = ['pdf', 'fb2', 'epub', 'lit', 'mobi', 'djvu', 'djv', 'txt', 'rtf', 'azw', 'azw3', 'cbz'];

$response = httpGet("http://$base/interface-data/update");
$update = json_decode($response, true);
$library = $update['default_library_id'];

$offset = 0;
$total = 1;

while ($offset < $total) {
    $request = [
        "offset" => $offset,
        "query" => "",
        "sort" => "timestamp",
        "sort_order" => "desc",
        "vl" => "",
    ];
    $response = httpGet("http://$base/interface-data/more-books?library_id=$library", json_encode($request));
    $books = json_decode($response, true);
    $dir = getSiteDirectory($base);
    $total = $books['search_result']['total_num'];

    foreach ($books['metadata'] as $id => $metadata) {
        $offset += 1;

        $isbn = $metadata['identifiers']['isbn'] ?? '';
        $isbn = cleanISBN($isbn);

        echo "$offset/$total $metadata[title]\n";

        if (str_contains($metadata['title'], 'z-lib')) continue;

        try {
            if (isValidISBN($isbn)) {
                if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                    continue;
                }
            } else {
                $search = $metadata['title'] . ' '. $metadata['author_sort'];
                if (inAnnasArchive($search)) continue;
            }
        } catch (Exception $e) {
            continue;
        }

        foreach ($metadata['formats'] as $format) {
            $ext = strtolower($format);
            if (!in_array($ext, $allowed_formats)) continue;

            $filename = trim("$isbn $metadata[title] - {$metadata['authors'][0]}.$ext");
            $url = "http://$base/get/$format/$id/$library";

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