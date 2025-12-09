<?php

function checkExistence($path) {
    $md5 = strtoupper(md5_file($path));
    $url = "https://genesis:upload@library.bz/api/check_existence/$md5";
    $response = httpGet($url);
    return $response !== "[]";
}

function uploadFile($path) {
    $url = 'https://genesis:upload@library.bz/main/upload/';
    $cfile = new CURLFile($path);
    $data = ["file" => $cfile];

    $ch = curl_init($url);
    // curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: library.bz",
        "Origin: https://library.bz",
        "Referer: https://library.bz/",
    ]);

    ProgressBar::showFor($ch);

    curl_exec($ch);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return $redirect_url;
}

function submitMetadata($url, $metadata) {
    $formHtml = httpGet($url);
    $formData = getFormValues($formHtml);
    foreach ($formData as $key => $value) {
        if (isset($metadata[$key]) && (empty($formData[$key]) || !empty($metadata[$key]))) {
            $formData[$key] = $metadata[$key];
        }
    }

    // post formData to $url
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: library.bz",
        "Origin: https://library.bz",
        "Referer: https://library.bz/",
    ]);
    curl_exec($ch);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return $redirect_url;
}

function lgpSubmitMetadata($ftp_path, $metadata) {
    $lgp_session_id = LIBGEN_SESSIONID;

    $url = 'https://libgen.bz/librarian.php';
    $pre_lg_topic = 'l';
    if (defined('PRE_LG_TOPIC')) $pre_lg_topic = PRE_LG_TOPIC;
    $formData = [
        'pre_lg_topic' => $pre_lg_topic,
        'action' => 'add',
        'object' => 'ftoe',
        'ftppath' => $ftp_path,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: libgen.bz",
        "Origin: https://libgen.bz",
        "Referer: https://libgen.bz/",
        "Cookie: phpbb3_9na6l_sid=$lgp_session_id",
    ]);
    $formHtml = curl_exec($ch);
    curl_close($ch);

    if (empty($formHtml)) {
        throw new UploadException("empty form");
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
    $isbns = explode(', ', $metadata['isbn']);
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

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: libgen.bz",
        "Origin: https://libgen.bz",
        "Referer: https://libgen.bz/",
        "Cookie: phpbb3_9na6l_sid=$lgp_session_id",
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    // if (preg_match_all('~<div class="alert alert-danger" role="alert">([^<]*)</div>~', $response, $matches)) {
    //     var_dump($matches[1][1]);
    // }

    return str_contains($response, 'The file is added in a repository successfully');
}

function ftpUpload($path, $metadata) {
    $newtitle = createFtpFileName($path, $metadata);
    echo "FTP upload to libgen..";

    $dest = LIBGEN_FTP_URL . $newtitle;
    $res = copy($path, $dest);
    echo ".";

    if ($res)
        try {
            $res = lgpSubmitMetadata($dest, $metadata);
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

function formUpload($path, $metadata) {
    if (checkExistence($path)) {
        echo "Already exists in libgen.\n";
        return true;
    }
    $url = uploadFile($path);
    // if (empty($url)) {
    //     echo "Upload failed. Retry:\n";
    //     $url = uploadFile($path);
    // }
    if (empty($url)) {
        throw new UploadException("upload failed");
    }

    $url2 = submitMetadata($url, $metadata);
    var_dump($url2);
    if (!empty($url2)) {
        echo "Submitted metadata.\n";
        return true;
    } else {
        // system("xdg-open $url");
        return false;
    }
}

function upload($path, $metadata) {
    if (filesize($path) < 12000) {
        throw new UploadException("file too small");
    }

    $zlib = false;
    $ftp = false;

    if (!empty($metadata['authors'])) {
        try {
            zlibUpload($path, $metadata);
            $zlib = true;
        } catch (UploadException $e) {
            echo $e."\n";
            $zlib = false;
        }
    }

    try {
        $ftp = ftpUpload($path, $metadata);
    } catch (UploadException $e) {
        echo $e."\n";
        $ftp = false;
    }

    return $ftp || $zlib;
}

