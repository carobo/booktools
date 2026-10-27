<?php

function lgpWebUploadFile($path, $metadata) {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;

    $url = "https://$libgen_host/librarian.php";
    $cfile = new CURLFile($path);
    $data = [
        "pre_lg_topic" => $metadata['pre_lg_topic'] ?? 'l',
        'action' => 'add',
        'object' => 'ftoe',
        'ftppath' => '',
        'ftoe_id' => '',
        'uploadedfile' => $cfile,
    ];
    $headers = [
        "Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=$lgp_session_id",
    ];

    $response = httpGet($url, $data, $headers);

    if (empty($response)) {
        throw new UploadException("upload failed");
    }

    if (preg_match_all('~<div class="alert alert-danger" role="alert">(.*?)</div>~', $response, $matches)) {
        if (isset($matches[1][1])) {
            $error = $matches[1][1];
            if (str_contains($error, 'Such a file is already in database.')) {
                throw new FileAlreadyPresentException($error);
            }
            throw new UploadException($error);
        }
    }

    return $response;
}

function lgpFormSubmitMetadata($formHtml, $metadata) {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;

    $url = "https://$libgen_host/librarian.php";

    if (empty($formHtml)) {
        throw new UploadException("empty form");
    }
    if (!str_contains($formHtml, "ON SITE AS")) {
        throw new UploadException("not logged in");
    }

    echo '.';

    $formData = getFormValues($formHtml);
    foreach ($formData as $key => $value) {
        if (isset($metadata[$key]) && (empty($formData[$key]) || !empty($metadata[$key]))) {
            $formData[$key] = $metadata[$key];
        }
    }
    $formData['author'] = $metadata['authors'] ?? '';
    $libgen_topic = lgpTopic($metadata);
    $formData['pre_lg_topic'] = $libgen_topic;
    $formData['libgen_topic'] = $libgen_topic;
    $formData['type'] = lgpEditionType($metadata);
    $isbns = mergeIsbnsArray($metadata['isbn']);
    for ($i = 1; $i <= count($isbns); $i++) {
        $formData["new_{$i}_505"] = $isbns[$i- 1];
    }

    $issns = cleanIssns($metadata['issn'] ?? []);
    for ($i = 1; $i <= count($issns); $i++) {
        $formData["new_{$i}_501"] = $issns[$i - 1];
    }

    if (isArticleMetadata($metadata)) {
        $series = $metadata['series'] ?? $metadata['journal'] ?? null;
        if (!empty($series)) {
            $series_id = lgpEnsureSeriesId($series, $metadata, $libgen_topic);
            if ($series_id) {
                $formData['issue_s_id'] = $series_id;
            }
        }
        if (!empty($metadata['volume'])) {
            $formData['issue_volume'] = $metadata['volume'];
        }
        if (!empty($metadata['issue'])) {
            $formData['issue_number'] = $metadata['issue'];
        }
        if (!empty($metadata['first_page'])) {
            $formData['issue_first_page'] = $metadata['first_page'];
        }
        if (!empty($metadata['last_page'])) {
            $formData['issue_last_page'] = $metadata['last_page'];
        }
        $doi = cleanDoi($metadata['doi'] ?? null);
        if ($doi) {
            $formData['doi'] = $doi;
        }
    }

    if (!empty($metadata['language'])) {
        $formData['new_1_101'] = getLanguageCode($metadata['language']);
    }
    $headers = [
        'Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=' . LIBGEN_SESSIONID,
    ];

    $response = httpGet($url, $formData, $headers);

    if (preg_match_all('~<div class="alert alert-danger" role="alert">([^<]*)</div>~', $response, $matches)) {
        if (isset($matches[1][1])) var_dump($matches[1][1]);
    }

    return str_contains($response, 'The file is added in a repository successfully');
}

function lgpTopic($metadata) {
    $topic = $metadata['pre_lg_topic'] ?? 'l';
    if (isArticleMetadata($metadata)) {
        $type = normalizeDocumentType($metadata['document_type'] ?? $metadata['content_type'] ?? null);
        if ($type === 'magazine') {
            return 'm';
        }
        if (!in_array($topic, ['a', 'm'], true)) {
            return 'a';
        }
    }
    return $topic;
}

function lgpEditionType($metadata) {
    if (!empty($metadata['libgen_type'])) {
        return $metadata['libgen_type'];
    }
    $type = normalizeDocumentType($metadata['document_type'] ?? null);
    return match ($type) {
        'article' => 'a',
        'journal' => 'ji',
        'magazine' => 'm',
        default => 'b',
    };
}

function lgpSeriesType($topic) {
    return $topic === 'm' ? 'mag' : 'j';
}

function lgpNormalizeSeriesTitle($title) {
    $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5);
    $title = preg_replace('~\s*\{\d+\}\s*$~', '', $title);
    $previous = null;
    while ($previous !== $title) {
        $previous = $title;
        $title = preg_replace('~\s*(?:\([^)]*\)|\[[^\]]*\])\s*$~', '', $title);
    }
    $title = mb_strtolower($title);
    $title = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $title);
    return trim(preg_replace('~\s+~', ' ', $title));
}

function lgpFindSeriesId($title, $topic = 'a') {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;
    $headers = [
        'Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=' . LIBGEN_SESSIONID,
    ];

    $url = "https://$libgen_host/jsonsearch.php?series=" . urlencode($title);
    $response = httpGet($url, null, $headers);
    $results = json_decode($response, true);
    if (!is_array($results)) {
        return null;
    }

    $wanted = lgpNormalizeSeriesTitle($title);
    foreach ($results as $result) {
        $candidate = $result['value'] ?? '';
        if (lgpNormalizeSeriesTitle($candidate) === $wanted) {
            return $result['id'] ?? null;
        }
    }
    return null;
}

function lgpEnsureSeriesId($title, $metadata, $topic = 'a') {
    static $cache = [];

    $key = "$topic:" . lgpNormalizeSeriesTitle($title);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $id = lgpFindSeriesId($title, $topic);
    if (!$id) {
        echo "Creating libgen series \"$title\"...";
        $id = lgpCreateSeries($title, $metadata, $topic);
        if ($id) echo " $id.\n";
    }
    $cache[$key] = $id;
    return $id;
}

function lgpCreateSeries($title, $metadata, $topic = 'a') {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;
    $headers = [
        'Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=' . LIBGEN_SESSIONID,
    ];

    $formHtml = httpGet("https://$libgen_host/librarian.php?action=add&object=s", null, $headers);
    if (empty($formHtml) || !str_contains($formHtml, 'Add new series')) {
        throw new UploadException("could not load series form");
    }
    $formData = getFormValues($formHtml);
    $formData['action'] = 'addreg';
    $formData['object'] = 's';
    $formData['title'] = $title;
    $formData['libgen_topic'] = $topic;
    $formData['type'] = lgpSeriesType($topic);
    if (!empty($metadata['publisher'])) {
        $formData['publisher'] = $metadata['publisher'];
    }
    $issns = cleanIssns($metadata['issn'] ?? []);
    for ($i = 1; $i <= count($issns); $i++) {
        $formData["new_{$i}_501"] = $issns[$i - 1];
    }

    $response = httpGet("https://$libgen_host/librarian.php", $formData, $headers);
    if (preg_match('~series\.php\?id=([0-9]+)~', $response, $matches)) {
        return $matches[1];
    }
    if (preg_match_all('~<div class="alert alert-danger" role="alert">([^<]*)</div>~', $response, $matches)) {
        foreach ($matches[1] as $error) {
            if (!str_contains($error, 'does not support JavaScript')) {
                throw new UploadException("could not create series: $error");
            }
        }
    }

    return lgpFindSeriesId($title, $topic);
}

function lgpLoadFtpPath($ftp_path, $metadata) {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;

    $url = "https://$libgen_host/librarian.php";
    $pre_lg_topic = $metadata['pre_lg_topic'] ?? 'l';
    $formData = [
        'pre_lg_topic' => $pre_lg_topic,
        'action' => 'add',
        'object' => 'ftoe',
        'ftppath' => $ftp_path,
    ];
    $headers = [
        'Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=' . LIBGEN_SESSIONID,
    ];

    $formHtml = httpGet($url, $formData, $headers);
    return $formHtml;
}

function lgpFtpUpload($path, $metadata) {
    $newtitle = createFtpFileName($path, $metadata);

    $dest = LIBGEN_FTP_URL . $newtitle;
    $res = copy($path, $dest);
    echo ".";

    if (!$res) {
        $res = file_exists($dest);
    }

    if ($res) {
        try {
            $formHtml = lgpLoadFtpPath($dest, $metadata);
            echo '.';
            $res = lgpFormSubmitMetadata($formHtml, $metadata);
        } catch (Exception $e) {
            echo $e."\n";
            $res = false;
        }
    }

    return $res;
}

function lgpWebUpload($path, $metadata) {
    $formHtml = lgpWebUploadFile($path, $metadata);
    echo '.';
    return lgpFormSubmitMetadata($formHtml, $metadata);
}

function upload($path, $metadata) {
    if (filesize($path) < 22000) {
        throw new UploadException("file too small");
    }

    $zlib = false;
    $web = false;
    $ftp = false;

    if (defined('ZLIB_UPLOAD') && ZLIB_UPLOAD) {
        try {
            zlibUpload($path, $metadata);
            $zlib = true;
        } catch (FileAlreadyPresentException $e) {
            echo " already present.\n";
            $zlib = true; 
        } catch (Exception $e) {
            echo " failed: $e\n";
            $zlib = false;
        }
    }

    $can_upload_libgen = true;
    if (isArticleMetadata($metadata) && empty($metadata['series']) && empty($metadata['journal'])) {
        echo "No journal/series name found; skipping libgen.\n";
        $can_upload_libgen = false;
    }

    if ($can_upload_libgen && (!$zlib || (defined('LIBGEN_UPLOAD') && LIBGEN_UPLOAD))) {
        if (filesize($path) < 10000000) {
            echo "Web upload to libgen...";
            try {
                $web = lgpWebUpload($path, $metadata);
                if ($web) echo " success.\n";
            } catch (FileAlreadyPresentException $e) {
                echo " already present.\n";
                $web = true;
            } catch (Exception $e) {
                echo " failed: $e\n";
                $web = false;
            }
        }

        if (!$web) {
            echo "FTP upload to libgen...";
            try {
                $ftp = lgpFtpUpload($path, $metadata);
                if ($ftp) echo " success.\n";
            } catch (FileAlreadyPresentException $e) {
                echo " already present.\n";
                $ftp = true;
            } catch (Exception $e) {
                echo " failed: $e\n";
                $ftp = false;
            }
        }
    }

    return $ftp || $web || $zlib;
}

function libgenTopics() {
    return [
        'l' => 'Non-fiction books',
        'f' => 'Fiction books',
        'a' => 'Scientific articles',
        'r' => 'Fiction books Rus',
        'c' => 'Comics',
        'm' => 'Magazines',
        's' => 'Standards',
    ];
}

function libgenGetRequestedBooks() {
    $html = httpGet('https://libgen.bz/index.php?req=mode:req&curtab=e');
    $isbns = grepIsbns($html);
    sort($isbns);
    $fp = fopen('libgen_requested.txt', 'w');
    foreach ($isbns as $isbn) {
        if (strlen($isbn) != 13) {
            $isbn = convertISBN10toISBN13($isbn);
        }
        if (strlen($isbn) == 13) {
            fwrite($fp, "$isbn\n");
        }
    }
    fclose($fp);
}
