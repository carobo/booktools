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
    $formData['libgen_topic'] = 'l';
    $formData['type'] = 'b';
    $isbns = mergeIsbnsArray($metadata['isbn']);
    for ($i = 1; $i <= count($isbns); $i++) {
        $formData["new_{$i}_505"] = $isbns[$i- 1];
    }

    if (!empty($metadata['issn'])) {
        $issns = $metadata['issn'];
        if (!is_array($issns)) $issns = explode(', ', $issns);
        for ($i = 1; $i <= count($issns); $i++) {
            $formData["new_{$i}_501"] = $issns[$i- 1];
        }
    }

    $formData['new_1_101'] = getLanguageCode($metadata['language']);
    $headers = [
        'Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=' . LIBGEN_SESSIONID,
    ];

    $response = httpGet($url, $formData, $headers);

    if (preg_match_all('~<div class="alert alert-danger" role="alert">([^<]*)</div>~', $response, $matches)) {
        if (isset($matches[1][1])) var_dump($matches[1][1]);
    }

    return str_contains($response, 'The file is added in a repository successfully');
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
    echo "FTP upload to libgen..";

    $dest = LIBGEN_FTP_URL . $newtitle;
    $res = copy($path, $dest);
    echo ".";

    if (!$res) {
        $res = file_exists($dest);
    }

    if ($res)
        try {
            $formHtml = lgpLoadFtpPath($dest, $metadata);
            echo '.';
            $res = lgpFormSubmitMetadata($formHtml, $metadata);
        } catch (Exception $e) {
            echo $e."\n";
            $res = false;
        }

    if ($res) {
        echo " success.\n";
    } else {
        echo " failed.\n";
    }

    return $res;
}

function lgpWebUpload($path, $metadata) {
    $formHtml = lgpWebUploadFile($path, $metadata);
    echo '.';
    return lgpFormSubmitMetadata($formHtml, $metadata);
}

function upload($path, $metadata) {
    if (filesize($path) < 12000) {
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
        } catch (UploadException $e) {
            echo " failed: $e\n";
            $zlib = false;
        }
    }

    if (!$zlib || (defined('LIBGEN_UPLOAD') && LIBGEN_UPLOAD)) {
        if (filesize($path) < 10000000) {
            echo "Web upload to libgen...";
            try {
                $web = lgpWebUpload($path, $metadata);
                if ($web) echo " success.\n";
            } catch (FileAlreadyPresentException $e) {
                echo " already present.\n";
                $web = true;
            } catch (UploadException $e) {
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
            } catch (UploadException $e) {
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
