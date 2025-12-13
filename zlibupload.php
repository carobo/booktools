<?php

$_zlibSessionData = [
    'cookies' => [],
];

function zlibFtpCollection($id)
{
    $url = "https://z-library.sk/papi/uploader/collection/$id/files";

    $files       = [];
    $page        = 1;
    $totalPages  = 1;          // will be overwritten by the first response

    while ($page <= $totalPages) {
        // Payload for this page
        $payload = [
            'page'   => $page,
            'path'   => '',
            'status' => '',
        ];

        // Make the request (POST with JSON body)
        $response = zlibHttpRequest(
            $url,
            json_encode($payload),
            ["Content-Type: application/json"]
        );

        // Decode the JSON payload
        $resdata = json_decode($response, false);
        if ($resdata === null) {
            // Optional: log the error or throw an exception
            throw new UnexpectedResponseException($response);
        }

        // Merge the files returned by this page
        if (!empty($resdata->files)) {
            $files = array_merge($files, $resdata->files);
        }

        // Update paging info for the next loop iteration
        $totalPages = $resdata->pagination->pagesTotal ?? 1;
        $page++;
    }

    return $files;
}

function zlibConfirm($book)
{
    $id  = $book->id;
    $url = "https://z-library.sk/papi/book-temporary/{$id}/confirm";

    $postfields = [
        'content_type' => 'book',
    ];

    return zlibHttpRequest($url, $postfields);
}


function zlibUploadCover($path, $book)
{
    // Only PDF files may be turned into a PNG cover.
    if (!str_ends_with($path, '.pdf')) {
        return false;
    }

    // Generate a PNG from the PDF’s first page.
    $png_path = pdfCover($path);

    // Build the request payload.
    $id  = $book->id;
    $url = "https://z-library.sk/papi/book-temporary/{$id}/cover";
    $data = [
        'cover' => new CURLFile($png_path)   // multipart upload
    ];

    // Delegate the cURL call to the helper.
    // zlibHttpRequest will attach the common headers (Host, Cookie, …).
    $response = zlibHttpRequest($url, $data);

    // You could inspect `$response` here if you need to know the
    // success/failure status returned by the API.  For now we keep
    // the original behaviour and just return `true`.
    return true;
}

function zlibSetMetadata($book, $metadata)
{
    /* ------------------------------------------------------------------
     * 1  Build the form payload
     * ------------------------------------------------------------------ */
    $form = [
        'title'       => '',
        'author'      => '',
        'language'    => '',
        'publisher'   => '',
        'series'      => '',
        'volume'      => '',
        'edition'     => '',
        'year'        => '',
        'pages'       => '',
        'description' => '',
    ];

    /* Keep values from `$metadata` if present, otherwise fall back to the
       original book data. */
    foreach ($form as $key => $default) {
        $form[$key] = $metadata[$key] ?? $book->{$key};
    }

    /* ISBN handling – may be a string or an array. */
    $form['isbn'] = $metadata['isbn'];
    if (!is_array($form['isbn'])) {
        $form['isbn'] = explode(', ', $metadata['isbn']);
    }

    /* Some metadata may contain ISSN values – merge them with the ISBNs. */
    if (!empty($metadata['issn']) && is_array($metadata['issn'])) {
        $form['isbn'] = array_merge($form['isbn'] ?? [], $metadata['issn']);
    }

    /* Override a few keys that use slightly different names in the API. */
    $form['author']      = $metadata['authors'] ?? $metadata['author'] ?? '';
    $form['language']    = strtolower($metadata['language']);
    $form['content_type'] = 'book';

    /* ------------------------------------------------------------------
     * 2  Make the request
     * ------------------------------------------------------------------ */
    $url = "https://z-library.sk/papi/book-temporary/{$book->id}";
    $response = zlibHttpRequest($url, http_build_query($form));

    /* ------------------------------------------------------------------
     * 3  Handle the response
     * ------------------------------------------------------------------ */
    $decoded = json_decode($response);
    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }

    return $decoded->book;
}

function zlibSetFromMeta($book)
{
    $url = "https://z-library.sk/papi/book-temporary/{$book->id}/set-from-meta";

    // Let the helper do all the cURL plumbing.
    $response = zlibHttpRequest($url);

    // Decode and validate the response.
    $decoded = json_decode($response);
    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }

    return $decoded->book;
}

function zlibUploadFile($path)
{
    $url  = 'https://z-library.sk/papi/book-temporary/upload';
    $data = ['file' => new CURLFile($path)];

    // Delegate the cURL call to the helper.
    $response = zlibHttpRequest($url, $data);

    $decoded = json_decode($response);
    if (empty($decoded) || empty($decoded->success)) {
        throw new UploadException($response);
    }

    return $decoded->book;
}

function zlibMetadataFromIsbn($book)
{
    $decoded = null;                     // keep the last response (or null)
    $isbns   = explode(',', $book->identifier);

    foreach ($isbns as $isbn) {
        $url  = "https://z-library.sk/papi/book-meta/fetch-by-isbn/{$isbn}";
        $data = ['bookId' => $book->id]; // POST body

        // Delegate the cURL call to the helper.
        $response = zlibHttpRequest($url, $data);

        $decoded = json_decode($response);
        if ($decoded->success) {
            return $decoded;             // first successful lookup
        }
    }

    return $decoded;                     // null or last failed response
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
    return zlibFtpUpload($path, $metadata);

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

function verify_ctoken($token) {
    $index = hexdec(substr($token, 0, 1));
    $hash = sha1($token);
    echo "SHA1($token) == \n";
    echo $hash."\n";
    echo str_repeat('  ', $index) . "^^^^ == b00b\n";

    $success = substr($hash, $index * 2, 4) === 'b00b';
    return $success;
}

function calculate_ctoken($token) {
    $index = hexdec(substr($token, 0, 1));
    for ($i = 0; $i < 1e9; $i++) {
        $hash = sha1($token . $i);
        $correct = substr($hash, $index * 2, 4) === 'b00b';
        if ($correct) {
            return $token . $i;
        }
    }
    return false;
}

function _zlibHandleHeaders($curlHandle, $headerData) {
    global $_zlibSessionData;
    if (preg_match('~set-cookie: ([^=;]+)=([^; ]+)~i', $headerData, $matches)) {
        $name = $matches[1];
        $value = $matches[2];
        $_zlibSessionData['cookies'][$name] = $value;
    }
    return strlen($headerData);
}

function zlibHttpRequest($url, $postfields=null, $headers=[]) {
    global $_zlibSessionData;

    if (empty($_zlibSessionData['cookies']['c_token'])) {
        $_zlibSessionData['cookies']['c_token'] = '1'; // prevent recursive loop
        zlibInitializeCtoken();
    }

    if (defined('ZLIB_COOKIES')) {
        $cookies[] = ZLIB_COOKIES;
    }
    foreach ($_zlibSessionData['cookies'] as $name => $value) {
        $cookies[] = "$name=$value";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    if (!empty($postfields)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, '_zlibHandleHeaders');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: z-library.sk",
        "Origin: https://z-library.sk",
        "Referer: https://z-library.sk/",
        "Cookie: " . implode('; ', $cookies),
        "User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0",
        ...$headers,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function zlibInitializeCtoken() {
    global $_zlibSessionData;
    $response = zlibHttpRequest('https://z-library.sk/');
    if (preg_match("~const a0_0x2a54=\['([0-9A-F]+)','c_token='~", $response, $matches)) {
        $challenge = $matches[1];
        $token = calculate_ctoken($challenge);
        $_zlibSessionData['cookies']['c_token'] = $token;
        return $token;
    } else {
        throw new UnexpectedResponseException($response);
    }
}
