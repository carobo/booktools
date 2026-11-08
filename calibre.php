<?php

// Crawl calibre content-server

require_once('booktools.php');

$base = $argv[1];
echo "$base\n";

$dir = getSiteDirectory($base);

$existing = glob("$dir/*");
if (!empty($existing)) {
    echo "Already downloaded.\n";
    return;
}

$response = httpGet("$base/interface-data/update");
$update = json_decode($response, true);
$libraries = array_keys($update['library_map']);

foreach ($libraries as $library) {
    echo "Processing library $library\n";
    
    $offset = 0;
    $total = 1;

    while ($offset < $total) {
        $request = [
            "offset" => $offset,
            "query" => "isbn:9",
            "sort" => "timestamp",
            "sort_order" => "desc",
            "vl" => "",
        ];
        $response = httpGet("$base/interface-data/more-books?library_id=$library", json_encode($request));
        $books = json_decode($response, true);
        $total = $books['search_result']['total_num'];

        foreach ($books['metadata'] as $id => $metadata) {
            $offset += 1;

            $isbn = $metadata['identifiers']['isbn'] ?? '';
            $isbn = cleanISBN($isbn);

            echo "$offset/$total $metadata[title]\n";

            if (str_contains($metadata['title'], 'z-lib')) continue;
            if (str_contains($metadata['title'], 'Audiobook')) continue;

            $requested = isValidISBN($isbn) && isRequested($isbn);

            if (!$requested)
            try {
                if (isValidISBN($isbn)) {
                    if (zlibHasIsbn($isbn) || inAnnasArchive($isbn)) {
                        continue;
                    }
                }
                $search = cleanupSearchQuery($metadata['title'] . ' '. $metadata['author_sort']);
                if (inAnnasArchive($search)) continue;
            } catch (Exception $e) {
                // pass
            }

            $format = chooseFormatToDownload($metadata['formats']);
            if (!empty($format)) {
                $ext = strtolower($format);

                $filename = createFileName($id, $isbn, $metadata['title'], $metadata['authors'], $ext);
                $url = "$base/get/$format/$id/$library";

                if (file_exists("$dir/$filename")
                    || file_exists(replace_extension("$dir/$filename", "txt"))
                    || file_exists(replace_extension("$dir/$filename", "txt.gz"))) continue;

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
}

touch("$dir/calibre_processed.txt");