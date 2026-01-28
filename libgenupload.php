<?php

function checkExistence($path) {
    $libgen_host = LIBGEN_HOST;
    $md5 = strtoupper(md5_file($path));
    $url = "https://genesis:upload@$libgen_host/api/check_existence/$md5";
    $response = httpGet($url);
    return $response !== "[]";
}

function uploadFile($path) {
    $libgen_host = LIBGEN_HOST;
    $url = "https://genesis:upload@$libgen_host/main/upload/";
    $cfile = new CURLFile($path);
    $data = ["file" => $cfile];

    $ch = curl_init($url);
    // curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $libgen_host",
        "Origin: https://$libgen_host",
        "Referer: https://$libgen_host/",
    ]);

    ProgressBar::showFor($ch);

    curl_exec($ch);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    return $redirect_url;
}

function formSubmitMetadata($url, $metadata) {
    $libgen_host = LIBGEN_HOST;
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
        "Host: $libgen_host",
        "Origin: https://$libgen_host",
        "Referer: https://$libgen_host/",
    ]);
    curl_exec($ch);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    return $redirect_url;
}

function lgpSubmitMetadata($ftp_path, $metadata) {
    $libgen_host = LIBGEN_HOST;
    $lgp_session_id = LIBGEN_SESSIONID;

    $url = "https://$libgen_host/librarian.php";
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
        "Host: $libgen_host",
        "Origin: https://$libgen_host",
        "Referer: https://$libgen_host/librarian.php",
        "User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0",
        "Cookie: phpbb3_9na6l_u=1602; phpbb3_9na6l_k=; phpbb3_9na6l_sid=$lgp_session_id",
    ]);
    $formHtml = curl_exec($ch);

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

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $libgen_host",
        "Origin: https://$libgen_host",
        "Referer: https://$libgen_host/",
        "Cookie: phpbb3_9na6l_sid=$lgp_session_id",
    ]);
    $response = curl_exec($ch);

    if (preg_match_all('~<div class="alert alert-danger" role="alert">([^<]*)</div>~', $response, $matches)) {
        if (isset($matches[1][1])) var_dump($matches[1][1]);
    }

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

    $url2 = formSubmitMetadata($url, $metadata);
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

