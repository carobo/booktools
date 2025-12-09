<?php

function zlibFtpCollection($id) {
    $url = "https://z-library.sk/papi/uploader/collection/$id/files";

    $files = [];
    $page = 1;
    $totalPages = 1;

    while ($page <= $totalPages) {
        $data = [
            'page' => $page,
            'path' => '',
            'status' => '',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: z-library.sk",
            "Content-Type: application/json",
            "Origin: https://z-library.sk",
            "Referer: https://z-library.sk/",
            "Cookie: " . ZLIB_COOKIES,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $resdata = json_decode($response, false);
        $files = array_merge($files, $resdata->files);
        $totalPages = $resdata->pagination->pagesTotal;
        $page += 1;
    }
    return $files;
}

function zlibConfirm($book) {
    $id = $book->id;
    $url = "https://z-library.sk/papi/book-temporary/$id/confirm";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['content_type' => 'book']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . ZLIB_COOKIES,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    // $decoded = json_decode($response);
    // if (empty($decoded) || empty($decoded->success)) {
    //     throw new UploadException($response);
    // }
}

function zlibUploadCover($path, $book) {
    if (!str_ends_with($path, '.pdf')) {
        return false;
    }

    $png_path = pdfCover($path);

    $id = $book->id;
    $url = "https://z-library.sk/papi/book-temporary/$id/cover";
    $cfile = new CURLFile($png_path);
    $data = ["cover" => $cfile];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . ZLIB_COOKIES,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return true;
}

function zlibSetMetadata($book, $metadata) {
    // - PUT https://z-library.sk/papi/book-temporary/12345678
    // title=Financial+Accounting%3A+Fundamentals%2C+Analysis+and+Reporting
    // author=Arora%2C+R.+K.
    // categories%5B%5D=0
    // language=english
    // content_type=book
    // publisher=
    // series=
    // volume=
    // edition=
    // year=2017
    // pages=
    // description=
    // isbn%5B%5D=9788126583515

    $form = [
        'title' => '',
        'author' => '',
        'language' => '',
        'publisher' => '',
        'series' => '',
        'volume' => '',
        'edition' => '',
        'year' => '',
        'pages' => '',
        'description' => '',
    ];

    foreach ($form as $key => $default) {
        $form[$key] = $metadata[$key] ?? $book->{$key};
    }
    $form['isbn'] = $metadata['isbn'];
    if (!is_array($form['isbn'])) {
        $form['isbn'] = explode(', ', $metadata['isbn']);
    }
    if (!empty($metadata['issn']) && is_array($metadata['issn'])) {
        $form['isbn'] = array_merge($form['isbn'] ?? [], $metadata['issn']);
    }
    $form['author'] = $metadata['authors'] ?? $metadata['author'] ?? '';
    $form['language'] = strtolower($metadata['language']);
    $form['content_type'] = 'book';

    $url = "https://z-library.sk/papi/book-temporary/" . $book->id;
    $ch = curl_init($url);
    // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . ZLIB_COOKIES,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response);
    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }
    return $decoded->book;
}

function zlibSetFromMeta($book) {
    $id = $book->id;
    $url = "https://z-library.sk/papi/book-temporary/$id/set-from-meta";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . ZLIB_COOKIES,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response);
    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }
    return $decoded->book;
}

function zlibUploadFile($path) {
    $url = 'https://z-library.sk/papi/book-temporary/upload';
    $cfile = new CURLFile($path);
    $data = ["file" => $cfile];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . ZLIB_COOKIES,
    ]);

    ProgressBar::showFor($ch);

    $response = curl_exec($ch);-
    curl_close($ch);
    $decoded = json_decode($response);

    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }

    return $decoded->book;
}

function zlibMetadataFromIsbn($book) {
    $decoded = null;
    $isbns = explode(',', $book->identifier);
    foreach ($isbns as $isbn) {
        $url = "https://z-library.sk/papi/book-meta/fetch-by-isbn/$isbn";
        $data = ['bookId' => $book->id];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: z-library.sk",
            "Origin: https://z-library.sk",
            "Referer: https://z-library.sk/",
            "Cookie: " . ZLIB_COOKIES,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode($response);
        if ($decoded->success) {
            return $decoded;
        }
    }
    return $decoded;
}

function zlibFtpUpload($path, $metadata) {
    echo "FTP upload to zlib... ";
    $filename = createFtpFileName($path, $metadata);
    $result = copy($path, ZLIB_FTP_URL . $filename);
    if (!$result) {
        echo "failed.\n";
        throw new UploadException("zlib ftp upload");
    } else {
        echo "success.\n";
    }
    return $result;
}

function dotsleep() {
    echo '.';
    sleep(1);
    echo '.';
    sleep(1);
    echo '.';
}

function zlibWebUpload($path, $metadata) {
    $book = zlibUploadFile($path);
    echo "Sending metadata to zlib.";
    dotsleep();
    $book = zlibSetFromMeta($book);
    dotsleep();
    if (empty($metadata['authors'])) {
        $metadata['authors'] = $book->author;
    }
    if (empty($metadata['authors'])) {
        $fromZlib = zlibMetadataFromIsbn($book);
        echo '.';
        $metadata['authors'] = $fromZlib->book->author;
    }
    $book = zlibSetMetadata($book, $metadata);
    dotsleep();
    if (zlibUploadCover($path, $book)) {
        dotsleep();
    }

    if (defined('ZLIB_CONFIRM') && ZLIB_CONFIRM)
        zlibConfirm($book);

    echo " done.\n";
    return true;
}

function zlibUpload($path, &$metadata) {
    // This was already done by uploaddirectory.php:
    // $metadata['isbn'] = mergeIsbns($metadata['isbn'], getIsbnFromFile($path));

    if (empty($metadata['year'])) {
        $metadata['year'] = getYearFromFile($path);
    }
    if (empty($metadata['publisher'])) {
        $metadata['publisher'] = getPublisherFromFile($path);
    }
    if (empty($metadata['doi'])) {
        $metadata['doi'] = getDoiFromFile($path);
    }
    if (empty($metadata['issn'])) {
        $metadata['issn'] = getIssnFromFile($path);
    }

    // TODO temporary, to remove
    // return zlibFtpUpload($path, $metadata);

    if (filesize($path) > 100000000) {
        return zlibFtpUpload($path, $metadata);
    }

    try {
        return zlibWebUpload($path, $metadata);
    } catch (UploadException $e) {
        var_dump($e);
        if (str_contains($e->getMessage(), 'This book already exist:')) {
            return true;
        }
        return zlibFtpUpload($path, $metadata);
    }
}

function zlibFormValues($book_id) {
    $url = "https://z-library.sk/layer/_modals/book_temporary_edit_dialog?id=$book_id";
    return getFormValues(httpGet($url));
}
