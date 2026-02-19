<?php

$_zlibSessionData = [
    'cookies' => [],
];

function existsInZlib($isbns) {
    $zlibHost = ZLIB_HOST;

    if (is_string($isbns)) {
        $isbns = [$isbns];
    }
    foreach ($isbns as $isbn) {
        $result = zlibHttpRequest("https://$zlibHost/s/$isbn?e=1&selected_content_types%5B%5D=book");
        if (preg_match('~Books&nbsp;\(([0-9]+)\)~', $result, $matches)) {
            if ($matches[1] > 0) {
                return true;
            }
        }
    }
    return false;
}

function zlibFtpCollection($id)
{
    $zlibHost = ZLIB_HOST;
    $url = "https://$zlibHost/papi/uploader/collection/$id/files";

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
    $zlibHost = ZLIB_HOST;
    $id  = $book->id;
    $url = "https://$zlibHost/papi/book-temporary/{$id}/confirm";

    $postfields = [
        'content_type' => 'book',
    ];

    return zlibHttpRequest($url, $postfields);
}


function zlibUploadCover($path, $book)
{
    $zlibHost = ZLIB_HOST;

    // Only PDF files may be turned into a PNG cover.
    if (!str_ends_with($path, '.pdf')) {
        return false;
    }

    // Generate a PNG from the PDF’s first page.
    $png_path = pdfCover($path);

    // Build the request payload.
    $id  = $book->id;
    $url = "https://$zlibHost/papi/book-temporary/{$id}/cover";
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
    $zlibHost = ZLIB_HOST;

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
    $form['isbn'] = mergeIsbnsArray($metadata['isbn']);

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
    $url = "https://$zlibHost/papi/book-temporary/{$book->id}";
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
    $zlibHost = ZLIB_HOST;
    $url = "https://$zlibHost/papi/book-temporary/{$book->id}/set-from-meta";

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
    $zlibHost = ZLIB_HOST;
    $url  = "https://$zlibHost/papi/book-temporary/upload";
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
    $zlibHost = ZLIB_HOST;
    $decoded = null;                     // keep the last response (or null)
    $isbns   = explode(',', $book->identifier);

    foreach ($isbns as $isbn) {
        $url  = "https://$zlibHost/papi/book-meta/fetch-by-isbn/{$isbn}";
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

function dotsleep($seconds = 2) {
    for ($i = 0; $i < $seconds; $i++) {
        echo '.';
        sleep(1);
    }
    echo '.';
}

function zlibWebUpload($path, $metadata) {
    echo "Web upload to zlib... ";
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
    if (empty($metadata['isbn'])) {
        $metadata['isbn'] = getIsbnFromFile($path);
    }

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

    if (preg_match('~/([0-9A-Fa-f]{32})\.~', $path, $matches)) {
        $md5 = $matches[1];
        if (existsInZlib($md5)) {
            return true;
        }
    }

    // TODO temporary, to remove
    // return zlibFtpUpload($path, $metadata);

    if (filesize($path) > 100000000) {
        return zlibFtpUpload($path, $metadata);
    }

    try {
        return zlibWebUpload($path, $metadata);
    } catch (Exception $e) {
        var_dump($e);
        if (str_contains($e->getMessage(), 'This book already exist:')) {
            return true;
        }
        return zlibFtpUpload($path, $metadata);
    }
}

function zlibFormValues($book_id) {
    $url = "https://$zlibHost/layer/_modals/book_temporary_edit_dialog?id=$book_id";
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
    $zlibHost = ZLIB_HOST;

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

    static $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    if (!empty($postfields)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        curl_setopt($ch, CURLOPT_POST, 0);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, '_zlibHandleHeaders');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $zlibHost",
        "Origin: https://$zlibHost",
        "Referer: https://$zlibHost/",
        "Cookie: " . implode('; ', $cookies),
        "User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0",
        ...$headers,
    ]);

    if (!empty($postfields['file'])) {
        ProgressBar::showFor($ch);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);

    if (!empty($error)) {
        throw new UnexpectedResponseException($error);
    }

    if (str_contains($response, 'Wait a moment, checking your browser')) {
        $_zlibSessionData['cookies']['c_token'] = null;
        // TODO throw exception when not coming from zlibInitializeCtoken?
        // Perhaps only on POST?
    }

    return $response;
}

function zlibInitializeCtoken() {
    global $_zlibSessionData;
    $zlibHost = ZLIB_HOST;
    $response = zlibHttpRequest("https://$zlibHost/");
    if (preg_match("~const a0_0x2a54=\['([0-9A-F]+)','c_token='~", $response, $matches)) {
        $challenge = $matches[1];
        $token = calculate_ctoken($challenge);
        $_zlibSessionData['cookies']['c_token'] = $token;
        return $token;
    } else {
        throw new UnexpectedResponseException($response);
    }
}

function zlibGetRequestedBooks($page = 1) {
    $zlibHost = ZLIB_HOST;
    $fp = fopen('zlib_requested.txt', 'w');
    while (true) {
        echo "$page\n";
        $url = "https://$zlibHost/requests?page=$page";
        $response = zlibHttpRequest($url);
        if (!preg_match_all('~isbn="([0-9]*[0-9X])"~', $response, $matches)) {
            echo $response;
            break;
        }
        foreach ($matches[1] as $isbn) {
            if (strlen($isbn) != 13) {
                $isbn = convertISBN10toISBN13($isbn);
            }
            if (strlen($isbn) == 13) {
                fwrite($fp, "$isbn\n");
            }
        }
        $page += 1;
        sleep(2);
    }
}
