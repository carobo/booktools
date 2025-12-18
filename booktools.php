<?php

class DownloadException extends Exception { }
class DecryptException extends DownloadException { }
class FileInputOutputException extends Exception { }
class UnexpectedResponseException extends Exception { }

final class ProgressBar {
    private int $dotsDrawn = 0;

    public function progress($resource, $download_size, $downloaded, $upload_size, $uploaded) {
        if ($upload_size != 0) {
            $pct = ($uploaded * 100) / $upload_size;
            if ($this->dotsDrawn == 0) {
                echo "\r" . str_repeat(" ", 99) . "|\r";
                $this->dotsDrawn += 1;
            }
            while ($this->dotsDrawn < $pct) {
                echo '.';
                $this->dotsDrawn += 1;
            }
            if ($this->dotsDrawn == 100) {
                echo "\n";
                $this->dotsDrawn += 1;
            }
        }
    }
    
    public static function showFor($ch) {
        $progress = new ProgressBar();
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, [$progress, 'progress']);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    }
}

function random_ip() {
    return sprintf("%d.%d.%d.%d", rand(1, 255), rand(0, 255), rand(0, 255), rand(1, 254));
}

function stripXML($response) {
    return preg_replace('~<\?xml version=.*~s', '', $response);
}

function httpSave($url) {
    $domain = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $origin = "$scheme://$domain";

    $url_path = parse_url($url, PHP_URL_PATH);
    $dir = getSiteDirectory($domain);
    $output_path = "$dir/$url_path";

    if (file_exists($output_path)) {
        echo "File already exists: $output_path\n";
        return $output_path;
    }

    $output_dir = dirname($output_path);
    if (!is_dir($output_dir)) mkdir($output_dir, 0777, true);

    $fp = fopen($output_path, 'w');
    if ($fp === false) {
        throw new FileInputOutputException($output_path);
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $domain",
        "Origin: $origin",
        "Referer: $origin",
        'Client-IP: ' . random_ip(),
        'X-Forwarded-For: ' . random_ip(),
        "Cookie: device_data=1-2-3",
    ]);

    $response = curl_exec($ch);

    // Check for any errors
    if (curl_errno($ch)) {
        throw new DownloadException(curl_error($ch));
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        throw new DownloadException($http_status);
    }

    // Close the cURL session
    curl_close($ch);
    fclose($fp);
    return $output_path;
}

function httpGet($url) {
    $domain = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $origin = "$scheme://$domain";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in response
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Host: $domain",
        "Origin: $origin",
        "Referer: $origin",
        'Client-IP: ' . random_ip(),
        'X-Forwarded-For: ' . random_ip(),
    ]);

    $response = curl_exec($ch);

    // Check for any errors
    if (curl_errno($ch)) {
        throw new DownloadException(curl_error($ch));
    }

    // Close the cURL session
    curl_close($ch);
    return $response;
}

function existsInZlib($isbns) {
    foreach ($isbns as $isbn) { 
        $result = httpGet("https://z-library.sk/s/$isbn?e=1&selected_content_types%5B%5D=book");
        preg_match('~Books&nbsp;\(([0-9]+)\)~', $result, $matches);
        if ($matches[1] > 0) {
            return true;
        }
    }
    return false;
}

function searchAnnasArchive($q) {
    $url = 'https://annas-archive.org/dyn/search_counts?' . http_build_query(['q' => $q]);
    // $url = 'https://annas-archive.li/dyn/search_counts?' . http_build_query(['q' => $q]);
    $response = httpGet($url);
    $decoded = json_decode($response);
    if (empty($response) || empty($decoded)) {
        throw new UnexpectedResponseException($response);
    }
    return $decoded;
}

function inAnnasArchive($isbn) {
    $counts = searchAnnasArchive($isbn);
    $count = $counts->aarecords->value;
    if ($count < 0) {
        throw new UnexpectedResponseException($count);
    }
    return $count !== 0;
}

final class FileWriter {
    private \resource $fh;
    public int $size = 0;

    public function __construct(
        public readonly string $path
    ) {
        $output_dir = dirname($path);
        if (!is_dir($output_dir)) mkdir($output_dir, 0777, true);
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new FileInputOutputException($path);
        }
        $this->fp = $fp;
    }

    public function __destruct() {
        fclose($this->fp);
    }

    public function write($curl, $data) {
        if ($this->size == 0 && str_starts_with($data, "\n")) {
            $data = substr($data, 1);
            $written = fwrite($this->fp, $data);
            $this->size += $written;
            return 1 + $written;
        }
        $written = fwrite($this->fp, $data);
        $this->size += $written;
        return $written;
    }
}

function mergeIsbns(...$parts) {
    $isbns = [];
    foreach ($parts as $part) {
        if (!is_array($part)) {
            $part = explode(', ', $part);
        }
        $isbns = array_merge($isbns, $part);
    }
    $isbns = array_map('cleanISBN', $isbns);
    $isbns = array_filter(array_unique($isbns));
    $isbns = array_filter($isbns, 'isValidISBN');
    return implode(', ', $isbns);
}

function mergeIsbnsArray(...$parts) {
    $isbns = [];
    foreach ($parts as $part) {
        if (!is_array($part)) {
            $part = explode(', ', $part);
        }
        $isbns = array_merge($isbns, $part);
    }
    $isbns = array_map('cleanISBN', $isbns);
    $isbns = array_filter(array_unique($isbns));
    $isbns = array_filter($isbns, 'isValidISBN');
    return $isbns;
}

function mergeMetadata($onix, $dbmeta) {
    $metadata = array_merge($onix, $dbmeta);
    $metadata['isbn'] = mergeIsbns($onix['isbn'] ?? [], $dbmeta['isbn'] ?? []);
    return $metadata;
}

function normalizeCase($str) {
    preg_match('~\b[A-Z]+\b~u', $str, $matches);
    foreach ($matches as $word) {
        $str = str_replace($word, ucfirst(strtolower($word)), $str);
    }
    return $str;
}

function escape_command($args) {
    return implode(' ', array_map('escapeshellarg', $args));
}

function parseIsbns($doc) {
    $allowedTypes = ['ISBN-10', 'ISBN-13', '02', '15'];

    $isbns = [];
    $prodids = $doc->getElementsByTagName('productidentifier');
    foreach ($prodids as $prodid) {
        $b221s = $prodid->getElementsByTagName('b221');
        $b244s = $prodid->getElementsByTagName('b244');

        $length = min($b221s->length, $b244s->length);
        for ($i = 0; $i < $length; $i++) {
            $types = explode(',', $b221s->item($i)->textContent);
            $values = explode(',', $b244s->item($i)->textContent);
            assert(count($types) === count($values));
            for ($j = 0; $j < count($types); $j++) {
                $type = $types[$j];
                $value = $values[$j];
                if (in_array($type, $allowedTypes)) {
                    $isbns[] = $value;
                }
            }
        }
    }
    return implode(', ', $isbns);
}

function parseOnix($path) {
    $fields = [
        "title" => "b203",
        "authors" => "b036",
        "keynames" => "b040",
        "langcode" => "b252",
        "edition" => "b057",
        // "pages" => "b061",
        "publisher" => "b081",
        "pubdate" => "b003",
    ];

    $languages = [
        'jpn' => 'Japanese',
        'jav' => 'Japanese', // Theoretically incorrect, but practically correct.
        'eng' => 'English',
        'por' => 'Portuguese',
        'spa' => 'Spanish',
        'ger' => 'German',
    ];

    $doc = new DOMDocument();
    $doc->load($path);

    $metadata = [];
    foreach ($fields as $key => $fieldid) {
        $nodes = $doc->getElementsByTagName($fieldid);
        $texts = [];
        foreach ($nodes as $node) {
            $texts[] = $node->textContent;
        }
        $metadata[$key] = implode(', ', $texts);
    }

    if (!empty($metadata['pubdate']) && preg_match('~^[0-9]{4}\b~', $metadata['pubdate'])) {
        $metadata['year'] = substr($metadata['pubdate'], 0, 4);
    }
    if (!empty($metadata['langcode'])) {
        $metadata['language'] = $languages[$metadata['langcode']];
    }
    $metadata['isbn'] = parseIsbns($doc);
    $metadata['onix'] = basename($path);

    if (empty($metadata['authors']) && !empty($metadata['keynames'])) {
        $metadata['authors'] = $metadata['keynames'];
    }
    return $metadata;
}

function guessLanguage($text) {
    $map = [
        'North American Spanish' => 'Spanish',
        'Spanish translation' => 'Spanish',
        'Spanish text' => 'Spanish',
        '(Hindi)' => 'Hindi',
        '(Bangla)' => 'Bengali',
        '(Odiya)' => 'Odia',
        'Malayalam Language' => 'Malayalam',
        'English Language' => 'English',
    ];
    foreach ($map as $key => $value) {
        if (str_contains($text, $key)) {
            return $value;
        }
    }
    if (defined('LANGUAGES')) {
        $languages = LANGUAGES;
    } else {
        $languages = ['en', 'es', 'pt', 'fr', 'pl', 'ja', 'hr', 'nl', 'lt', 'gu', 'ru', 'de'];
    }
    if (count($languages) === 1) {
        $code = $languages[0];
    } else {
        $detector = new LanguageDetector\LanguageDetector(null, $languages);
        // $detector = new LanguageDetector\LanguageDetector(null, ['en', 'hi']);
        $text = strip_tags(substr($text, 0, 20000));
        $code = $detector->evaluate($text)->getLanguage()->getCode();
    }
    try {
        $language = \Symfony\Component\Intl\Languages::getName($code);
    } catch (Exception $e) {
        var_dump($e);
        $language = null;
    }
    return $language;
}

function getLanguageCode($language) {
    $languages = \Symfony\Component\Intl\Languages::getAlpha3Names();
    $languages = array_flip($languages);
    return $languages[$language];
}

function fixMetadata($metadata) {
    if (is_array($metadata['authors'])) {
        $imploded = implode('; ', $metadata['authors']);
        if (!str_contains($imploded, 'Array')) {
            $metadata['authors'] = $imploded;
        }
    }
    if (!empty($metadata['authors'])) {
        $metadata['title'] = preg_replace($A = '~^' . preg_quote($metadata['authors'], '~') . '\s*:\s*~', '', $metadata['title']);
        $metadata['name'] = preg_replace($A = '~^' . preg_quote($metadata['authors'], '~') . '\s*:\s*~', '', $metadata['name'] ?? $metadata['title']);
    }
    if (empty($metadata['language'])) {
        // $text = $metadata['title'] . "\n" . strip_tags($metadata['description_long']);
        $text = strip_tags($metadata['description_long']) . "\n" . strip_tags($metadata['description_long']);
        $metadata['language'] = guessLanguage($text);
        // echo "Guessed language " . $metadata['language'] . "\n";
    }
    // if (empty($metadata['scan'])) {
    //     $metadata['scan'] = '0';
    // }
    if (empty($metadata['edition']) && preg_match('~\(([0-9]+)[a-z]{2} edition\)~i', $metadata['title'], $matches)) {
        $metadata['edition'] = $matches[1];
    }
    if (!empty($metadata['isbn'])) {
        $metadata['isbn'] = cleanISBN($metadata['isbn']);
    }
    if (empty($metadata['description']) && !empty($metadata['description_short'])) {
        $metadata['description'] = $metadata['description_short'];
    }
    if (empty($metadata['description']) && !empty($metadata['description_long'])) {
        $metadata['description'] = $metadata['description_long'];
    }
    return $metadata;
}

function cleanISBN($isbn) {
    return str_replace(['-', 'INK', ' ', '–'], ['', '978', '', ''], $isbn);
}

function fileToText($path) {
    if (str_ends_with(strtolower($path), '.txt')) {
        return file_get_contents($path);
    }
    if (str_ends_with(strtolower($path), '.pdf')) {
        return pdfToText($path);
    }
    if (str_ends_with(strtolower($path), '.epub')) {
        return epubToText($path);
    }
    if (str_ends_with(strtolower($path), '.djvu')) {
        return djvuToText($path);
    }
    if (str_ends_with(strtolower($path), '.tar.gz')) {
        return targzToText($path);
    }
    throw new InvalidArgumentException($path);
}

function targzToText($path) {
    if (!str_ends_with(strtolower($path), '.tar.gz')) {
        return '';
    }
    $txt_path = str_replace('.tar.gz', '.txt', strtolower($path));
    if (!file_exists($txt_path)) {
        $files = explode("\n", `tar -tzf "$path"`);
        $files = array_filter($files, function ($a) { return str_ends_with($a, '.txt'); });
        usort($files, function ($a, $b) {
            $am = preg_match('~(\d+).txt~', $a, $amatches);
            $bm = preg_match('~(\d+).txt~', $b, $bmatches);
            if ($am && $bm) {
                return ((int)$amatches[1]) <=> ((int)$bmatches[1]);
            }
            return $a <=> $b;
        });
        $text = '';
        for ($i = 0; $i < count($files); $i++) {
            if ($i <= 11 || $i == count($files) - 1) {
                $file = $files[$i];
                $text .= `tar -xzOf "$path" "$file"`;
            }
        }
        atomic_file_put_contents($txt_path, $text);
    } else {
        $text = file_get_contents($txt_path);
    }
    return $text;
}

function djvuToText($path) {
    if (!str_ends_with(strtolower($path), '.djvu')) {
        return '';
    }
    $cmd = escape_command([
        'djvutxt', '--page=1-8', $path
    ]);
    return `$cmd`;
}

function pdfToText($path) {
    if (!str_ends_with(strtolower($path), '.pdf')) {
        return '';
    }
    $txt_path = str_replace('.pdf', '.txt', strtolower($path));
    if (!file_exists($txt_path)) {
        $text = '';

        /*
        // OCR the first page of the PDF
        $cover = pdfCover($path);
        if ($cover) {
            $cmd = escape_command([
                'tesseract', '--psm', '11', $cover, '-'
            ]);
            $text .= `$cmd`;
        }
        */

        $cmd = escape_command([
            'pdftotext', '-f', '1', '-l', '6', '-nodiag', $path, '-'
        ]);
        $text .= `$cmd`;

        $numPages = numberOfPages($path);
        if ($numPages) {
            $cmd = escape_command([
                'pdftotext', '-f', $numPages, '-l', $numPages, '-nodiag', $path, '-'
            ]);
            $text .= `$cmd`;
        }

        // if (empty(trim($text))) {
        if (!isLegible($text)) {
            // pdftotext does not seem to work. Perhaps Hindi fonts?
            // OCR the first page of the PDF
            $cover = pdfCover($path);
            if ($cover) {
                $text = '';
                $cmd = escape_command([
                    'tesseract', '--psm', '11', '-l', 'eng', $cover, '-'
                ]);
                $text .= `$cmd`;

                $cmd = escape_command([
                    'tesseract', '--psm', '11', '-l', 'hin', $cover, '-'
                ]);
                $text .= `$cmd`;
            }
        }

        $back = pdfBackCover($path);
        if ($back) {
            // OCR the last page of the book
            /*
            $cmd = escape_command([
                'tesseract', '--psm', '11', $back, '-'
            ]);
            $text .= `$cmd`;
            */

            // Read any barcode on the back
            $cmd = escape_command([
                'ZXingReader', '-bytes', '-single', $back
            ]);
            $barcode = `$cmd`;
            if ($barcode) {
                $text .= "\n$barcode\n";
            }
        }

        // read config.txt
        $configtxt = readConfigTxt($path);
        if (!empty($configtxt['isbn'])) {
            $text .= "\n{$configtxt['isbn']}\n";
        }
        if (!empty($configtxt['title'])) {
            $text .= "\n{$configtxt['title']}\n";
        }

        atomic_file_put_contents($txt_path, $text);
    }
    if (!file_exists($txt_path)) {
        throw new DownloadException('could not extract text');
    }
    $txt = file_get_contents($txt_path);
    return $txt;
}

function epubToText($path) {
    if (!str_ends_with(strtolower($path), '.epub')) {
        return '';
    }
    $txt_path = str_replace('.epub', '.txt', strtolower($path));
    if (file_exists($txt_path)) {
        return file_get_contents($txt_path);
    }

    $container_txt = file_get_contents("zip://$path#META-INF/container.xml");
    if ($container_txt === false) {
        return false;
    }
    $rootfile_path = (string)(new SimpleXMLElement($container_txt))->rootfiles->rootfile['full-path'];
    $rootfile_txt = file_get_contents("zip://$path#$rootfile_path");
    
    try {
        $rootfile_xml = new SimpleXMLElement($rootfile_txt);
    } catch (Exception $e) {
        var_dump($e);
        return '';
    }

    $description = $rootfile_xml->metadata->description;
    $items = [];
    foreach ($rootfile_xml->manifest->item as $item) {
        $items[(string)$item['id']] = dirname($rootfile_path) . '/' . $item['href'];
    }

    // Take first five and last chapter
    $itemrefs = [];
    $count = count($rootfile_xml->spine->itemref);
    for ($i = 0; $i < $count; $i++) {
        if ($i < 5 || $i === $count - 1) {
            $itemrefs[] = $rootfile_xml->spine->itemref[$i];
        }
    }

    $texts = [];
    foreach ($itemrefs as $itemref) {
        $idref = (string)$itemref['idref'];
        $item = $items[$idref];
        if (str_starts_with($item, './')) {
            $item = substr($item, 2);
        }
        $html = file_get_contents("zip://$path#$item");

        /*
        // OCR the first image in the epub
        if (empty($texts)) {
            $doc = parseHtml($html);
            $images = $doc->getElementsByTagName('img');
            if ($images->length) {
                $cover = $images[0]->getAttribute('src');
                if ($cover) {
                    $cover = getAbsoluteFilename(dirname($item) . '/' . $cover);
                    $extension = pathinfo($cover, PATHINFO_EXTENSION);
                    $cover_path = str_replace('.epub', ".$extension", strtolower($path));
                    $res = copy("zip://$path#$cover", $cover_path);
                    if ($res) {
                        $cmd = escape_command([
                            'tesseract', '--psm', '11', $cover_path, '-'
                        ]);
                        $texts[] = `$cmd`;
                    }
                }
            }
        }
        */
        
        $text = html_entity_decode(strip_tags($html));
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000) . "...";
        }
        $texts[] = $text;
    }

    $texts[] = strip_tags($rootfile_xml->metadata->asXML());
    $text = implode("\n\n", $texts);
    atomic_file_put_contents($txt_path, $text);
    return $text;
}

function epubCover($path) {
    if (!str_ends_with(strtolower($path), '.epub')) {
        return false;
    }

    // Return existing image, if exists
    $without_ext = str_replace('.epub', '', $path);
    $exts = ['jpeg', 'jpg', 'png'];
    foreach ($exts as $ext) {
        $cover = "$without_ext.$ext";
        if (is_file($cover)) {
            return $cover;
        }
    }

    $container_txt = file_get_contents("zip://$path#META-INF/container.xml");
    if ($container_txt === false) {
        return false;
    }
    $rootfile_path = (string)(new SimpleXMLElement($container_txt))->rootfiles->rootfile['full-path'];
    $rootfile_txt = file_get_contents("zip://$path#$rootfile_path");
    $rootfile_xml = new SimpleXMLElement($rootfile_txt);
    $items = [];
    foreach ($rootfile_xml->manifest->item as $item) {
        $items[(string)$item['id']] = dirname($rootfile_path) . '/' . $item['href'];
    }

    // Take first chapter
    $idref = (string)$rootfile_xml->spine->itemref[0]['idref'];
    $item = $items[$idref];

    if (str_starts_with($item, './')) {
        $item = substr($item, 2);
    }
    $html = file_get_contents("zip://$path#$item");

    $doc = parseHtml($html);
    $images = $doc->getElementsByTagName('img');
    if ($images->length) {
        $cover = $images[0]->getAttribute('src');
        if ($cover) {
            $cover = getAbsoluteFilename(dirname($item) . '/' . $cover);
            $extension = pathinfo($cover, PATHINFO_EXTENSION);
            $cover_path = str_replace('.epub', ".$extension", strtolower($path));
            $res = copy("zip://$path#$cover", $cover_path);
            if ($res) {
                return $cover_path;
            }
        }
    }
    return false;
}

function fileCoverImage($path) {
    if (str_ends_with(strtolower($path), '.epub')) {
        return epubCover($path);
    }
    if (str_ends_with(strtolower($path), '.pdf')) {
        return pdfCover($path);
    }
    return false;
}

function epubMetadata($path) {
    if (!str_ends_with(strtolower($path), '.epub')) {
        throw new ValueError($path);
    }

    $container_txt = file_get_contents("zip://$path#META-INF/container.xml");
    $rootfile_path = (string)(new SimpleXMLElement($container_txt))->rootfiles->rootfile['full-path'];
    $rootfile_txt = file_get_contents("zip://$path#$rootfile_path");
    $rootfile_xml = new SimpleXMLElement($rootfile_txt);
    $dc_elems = $rootfile_xml->metadata->children('http://purl.org/dc/elements/1.1/');
    $metadata = (array)$dc_elems;
    $metadata['authors'] = $metadata['creator'];
    if (is_array($metadata['authors'])) {
        $metadata['authors'] = implode('; ', $metadata['authors']);
    }
    if (is_array($metadata['title'])) {
        $metadata['title'] = implode(' - ', $metadata['title']);
    }
    $metadata['isbn'] = $metadata['identifier'];

    while (is_array($metadata['language'])) {
        $metadata['language'] = $metadata['language'][0];
    }
    $languages = \Symfony\Component\Intl\Languages::getNames();
    $languageCode = trim(strtolower($metadata['language']));
    $language = $languages[$languageCode] ?? $languages[substr($languageCode, 0, 2)];
    $metadata['language'] = $language;

    return $metadata;
}

function getYearFromFile($path) {
    $txt = fileToText($path);
    if (preg_match_all('~(Copyright (© )?|&#169; |\n© )(20[012]\d)~i', $txt, $matches)) {
        return max($matches[3]);
    }
    if (preg_match_all('~&#x00A9; (20[012]\d) ~i', $txt, $matches)) {
        return max($matches[1]);
    }
    return null;
}

function fixCase($str) {
    if (strtoupper($str) === $str) {
        $str = mb_convert_case($str, MB_CASE_TITLE);
    }
    return $str;
}

function getPublisherFromFile($path) {
    $txt = fileToText($path);
    if (preg_match('~Copyright (© )?20[012]\d by (.*?),~i', $txt, $matches)) {
        return $matches[2];
    }
    if (preg_match('~Published by (.*?) [–-] 20[012]\d~', $txt, $matches)) {
        return $matches[1];
    }
    if (preg_match('~>&#x00A9; 20[012]\d (.*?)<~', $txt, $matches)) {
        return fixCase($matches[1]);
    }
    if (preg_match('~Copyright © 20[012]\d (.*?) Publishing~', $txt, $matches)) {
        return fixCase($matches[1]);
    }
    return null;
}

function getDoiFromFile($path) {
    $txt = fileToText($path);
    if (preg_match('~DOI: (10.[1-9][0-9.]{3,10}/\S+)~', $txt, $matches)) {
        return $matches[1];
    }
}

function getIssnFromFile($path) {
    $txt = fileToText($path);
    if (preg_match_all('~ISSN ([0-9]{4}-[0-9]{3}[0-9X])~', $txt, $matches)) {
        return array_unique($matches[1]);
    }
    return [];
}

function ebook_polish($epub) {
    assert(str_ends_with($epub, ".epub"));
    $orig = str_replace('.epub', '_orig.epub', $epub);
    rename($epub, $orig);
    $cmd = escape_command([
        'ebook-polish', 
        '--compress-images',
        // '--subset-fonts',
        $orig,
        $epub,
    ]);
    echo "Polishing " . basename($epub) . "...\n";
    passthru($cmd, $return);
    return $return === 0;
}

function crack_epub($epub_enc_path) {
    assert(str_contains($epub_enc_path, '_enc'));
    $plain_path = str_replace('_enc', '', $epub_enc_path);
    if (file_exists($plain_path)) {
        echo "File already exists: $plain_path\n";
        return $plain_path;
    }

    $bkcrack = 'bkcrack';
    $cmd = escape_command([$bkcrack, '-C', $epub_enc_path, '-c', 'mimetype', '-P', 'plain.zip', '-p', 'mimetype']);
    echo "$cmd\n";
    $keys = system($cmd, $result);
    $keys = explode(' ', $keys);
    if ($result !== 0 || count($keys) != 3) {
        throw new DecryptException($cmd);
    }

    $cmd = escape_command([$bkcrack, '-C', $epub_enc_path, '-k', ...$keys, '-D', $plain_path]);
    echo "$cmd\n";
    passthru($cmd, $result);
    if ($result !== 0 || !file_exists($plain_path)) {
        throw new DecryptException($cmd);
    }

    return $plain_path;

}

function pdf2djvu($pdfpath) {
    $djvupath = str_replace('.pdf', '.djvu', strtolower($pdfpath));
    $cmd = "pdf2djvu -o $djvupath $pdfpath";
    system($cmd, $result);
    if ($result !== 0 || !file_exists($djvupath)) {
        throw new UnexpectedResponseException($cmd);
    }
    return $djvupath;
}

function filesize_mib($path) {
    $bytes = filesize($path);
    return round($bytes / 1048576);
}

function reduce_file_size($path) {
    if (str_ends_with($path, '.epub')) {
        $command = escape_command(['reduce_epub_size.sh', $path]);
    } else if (str_ends_with(strtolower($path), '.pdf')) {
        if (file_exists("$path.orig.pdf")) {
            echo "Looks already shrunk!\n";
            return true;
        } else {
            copy($path, "$path.orig.pdf");
            $command = escape_command(['shrinkpdf.sh', '-r', '100', '-o', $path, "$path.orig.pdf"]);
        }
    }
    else {
        return false;
    }

    $filename = basename($path);
    $oldsize = filesize_mib($path);
    echo "Shrinking $filename from $oldsize MiB...\n";
    passthru($command, $exit_code);
    clearstatcache(false, $path);
    $newsize = filesize_mib($path);
    echo "Shrunk $filename from $oldsize to $newsize MiB\n";
    return $exit_code === 0;
}

function asciiCleanup($title) {
    if (is_array($title)) {
        $title = implode('; ', $title);
    }
    $title = trim($title);
    $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    $title = preg_replace('~[^0-9A-Za-z ]+~', '', $title);
    return $title;
}

function zlibHasIsbn($isbn) {
    $db = new mysqli('localhost', 'carobo', null, 'allthethings');
    $q = "SELECT * FROM zlib_isbn WHERE isbn='$isbn' LIMIT 1";
    $res = $db->query($q);
    $result = $res->fetch_all();
    return !empty($result);
}

function hasMD5($md5_hex) {
    $md5_bin = hex2bin($md5_hex);
    $binfile = 'md5.bin';
    $size = filesize($binfile);
    $count = $size / 16;

    $lower = 0;
    $upper = $count;

    $idx = (int)($count / 2);

    $fp = fopen($binfile, "rb");
    while (true) {
        fseek($fp, $idx * 16);
        $candidate = fread($fp, 16);
        $cmp = strcmp($candidate, $md5_bin);
        if ($cmp === 0) {
            return true;
        }
        if ($lower === $upper) {
            return false;
        }
        if ($cmp < 0) {
            // $candidate < $md5_bin
            $lower = $idx + 1;
        }
        if ($cmp > 0) {
            // $candidate > $md5_bin
            $upper = $idx;
        }
        $idx = (int)($lower + ($upper - $lower) / 2);
    }
}

function isValidISBN(string $isbn) {
    return isValidISBN13($isbn) || isValidISBN10($isbn);
}

function isValidISBN10(string $isbn): bool {
    $isbn = preg_replace('/[ -]/', '', $isbn);

    if (strlen($isbn) !== 10) {
        return false;
    }
    if (!preg_match('~^[0-9]{9}[0-9X]$~', $isbn)) {
        return false;
    }

    $weightedSum = 0;

    // 3. Loop through the 10 characters.
    for ($i = 0; $i < 10; $i++) {
        $char = $isbn[$i];
        $weight = 10 - $i;

        // Determine the numerical value of the digit/character.
        if (ctype_digit($char)) {
            // Digits 0-9 have their face value.
            $digitValue = (int)$char;
        } elseif ($i === 9 && $char === 'X') {
            // The check digit (last position, i=9) can be 'X', which represents 10.
            $digitValue = 10;
        } else {
            // Any other non-digit character (or 'X' in a non-check-digit position) is invalid.
            return false;
        }

        // 4. Calculate the weighted sum.
        $weightedSum += $digitValue * $weight;
    }

    // 5. The ISBN-10 is valid if the total weighted sum is divisible by 11.
    // This handles the case where the remainder (11 - (sum mod 11)) would be 11,
    // as 11 mod 11 is 0.
    return ($weightedSum % 11 === 0);
}

function isValidISBN13(string $isbn): bool {
    // 1. Clean the ISBN string: remove hyphens, spaces, and ensure it's only digits.
    $isbn = preg_replace('/[ -]/', '', $isbn);

    // 2. Basic length check. An ISBN-13 must be exactly 13 digits long.
    if (strlen($isbn) !== 13 || !ctype_digit($isbn)) {
        return false;
    }

    $sum = 0;

    // 3. Loop through the first 12 digits (index 0 to 11).
    for ($i = 0; $i < 12; $i++) {
        // Get the current digit and convert it to an integer.
        $digit = (int)$isbn[$i];

        // 4. Apply the alternating weights (1 and 3).
        // Odd-indexed positions (0, 2, 4, ...) get weight 1.
        // Even-indexed positions (1, 3, 5, ...) get weight 3.
        if ($i % 2 === 0) {
            $sum += $digit * 1;
        } else {
            $sum += $digit * 3;
        }
    }

    // 5. Add the 13th digit (the check digit at index 12).
    $check_digit = (int)$isbn[12];
    $sum += $check_digit;

    // 6. The ISBN is valid if the total sum is divisible by 10.
    return ($sum % 10 === 0);
}

function parseHtml($formHtml) {
    $formDoc = new DOMDocument();
    $old = error_reporting(E_ERROR | E_PARSE);
    $formDoc->loadHTML('<meta charset="utf8">' . $formHtml);
    error_reporting($old);
    return $formDoc;
}

function getFormValues($formHtml) {
    if (empty($formHtml)) {
        throw new UnexpectedResponseException("empty form html");
    }
    // echo $formHtml;

    $formData = ['scan' => ''];
    $formDoc = parseHtml($formHtml);
    $inputs = $formDoc->getElementsByTagName('input');
    foreach ($inputs as $input) {
        $key = $input->getAttribute('name');
        $value = $input->getAttribute('value');
        $formData[$key] = $value;
        if (str_ends_with($key, '[]')) {
            $formData[str_replace('[]', '', $key)][] = $value;
        }
    }
    $textareas = $formDoc->getElementsByTagName('textarea');
    foreach ($textareas as $textarea) {
        $key = $textarea->getAttribute('name');
        $value = $textarea->textContent;
        $formData[$key] = $value;
    }
    if (preg_match('~selected: \[{"text":"[^"]*","value":"([^"]*)"}\]~', $formHtml, $matches)) {
        $formData['language'] = $matches[1];
    }
    if (empty($formData)) {
        var_dump($formHtml);
        throw new UnexpectedResponseException("empty form");
    }
    return $formData;
}

function numberOfPages($path) {
    static $cache = [];

    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }

    if (str_ends_with(strtolower($path), '.pdf')) {
        $pages = trim(`qpdf --show-npages $path`);
        $cache[$path] = $pages;
        return $pages;
    }
    return null;
}

function recursive_glob($dir, $pattern) {
    $output = trim(`fdfind --glob '$pattern' '$dir'`);
    if (empty($output)) {
        return [];
    }
    return explode("\n", $output);
}

function readcsvs($dir) {
    $csvs = recursive_glob($dir, '*.csv');
    $rows_by_isbn13 = [];
    foreach ($csvs as $csv_path) {
        $fp = fopen($csv_path, 'r');
        $keys = fgetcsv($fp);
        $keys = array_map('strtolower', $keys);
        $ncols = count($keys);
        while ($row = fgetcsv($fp)) {
            $keyed = [];
            for ($i = 0; $i < $ncols; $i++) {
                $keyed[$keys[$i]] = fixEncoding($row[$i]);
            }
            if (!empty($keyed['isbn13'])) {
                $rows_by_isbn13[$keyed['isbn13']] = $keyed;
            }
        }
    }
    return $rows_by_isbn13;
}

function readonixxmls($dir) {
    $xml_paths = recursive_glob($dir, '*.xml');
    $all_metadata = [];
    foreach ($xml_paths as $path) {
        $xml = simplexml_load_file($path);
        $isbns = [];
        foreach ($xml->Product->ProductIdentifier as $pid) {
            if (isValidISBN($pid->IDValue)) {
                $isbns[] = (string) $pid->IDValue;
            }
        }
        $title = (string) $xml->Product->Title->TitleText;
        $authors = [];
        foreach ($xml->Product->Contributor as $c) {
            $authors[] = (string) $c->PersonName;
        }
        $langcode = (string) $xml->Product->Language->LanguageCode;
        $publisher = (string) $xml->Product->Publisher->PublisherName;
        $year = (string) $xml->Product->CopyrightYear;
        $languages = \Symfony\Component\Intl\Languages::getAlpha3Names();

        $metadata = [
            'title' => $title,
            'authors' => implode('; ', $authors),
            'publisher' => $publisher,
            'year' => $year,
            'language' => $languages[$langcode],
            'isbn' => $isbns,
        ];

        foreach ($isbns as $isbn) {
            $all_metadata[$isbn] = $metadata;
        }
    }
    return $all_metadata;
}

function fixEncoding($str) {
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = iconv('ISO-8859-1', 'UTF-8', $str);
    }
    return $str;
}

function pdfCover($path) {
    if (!str_ends_with(strtolower($path), '.pdf')) {
        throw new ValueError($path);
    }

    $path_without_ext = substr($path, 0, -4) . ".front";
    $png_path = "$path_without_ext.png";

    if (!file_exists($png_path)) {
        $cmd = escape_command([
            'pdftoppm', '-singlefile', '-cropbox', '-r', '80', '-png', $path, $path_without_ext
        ]);
        passthru($cmd);
    }

    return $png_path;
}

function pdfBackCover($path) {
    if (!str_ends_with(strtolower($path), '.pdf')) {
        throw new ValueError($path);
    }

    $pages = numberOfPages($path);
    if (empty($pages)) {
        return null;
    }

    $path_without_ext = substr($path, 0, -4) . ".back";
    $png_path = "$path_without_ext.png";

    if (!file_exists($png_path)) {
        $cmd = escape_command([
            'pdftoppm', '-singlefile', '-cropbox', '-r', '80', '-png', '-f', $pages, '-l', $pages, $path, $path_without_ext
        ]);
        passthru($cmd);
    }

    return $png_path;
}

// from https://stackoverflow.com/a/39796579
function getAbsoluteFilename($filename) {
    $path = [];
    foreach(explode('/', $filename) as $part) {
        // ignore parts that have no value
        if (empty($part) || $part === '.') continue;

        if ($part !== '..') {
            // cool, we found a new part
            array_push($path, $part);
        }
        else if (count($path) > 0) {
            // going back up? sure
            array_pop($path);
        } else {
            // now, here we don't like
            throw new \Exception('Climbing above the root is not permitted.');
        }
    }
    return join('/', $path);
}

function isLegible($txt) {
    if (empty($txt)) {
        return false;
    }

    $isAscii = [
        false => 0,
        true => 1,
    ];
    $mocking = [
        false => 1,
        true => 0,
    ];

    $prev = '';
    for ($i = 0; $i < strlen($txt); $i++) {
        $char = $txt[$i];
        $ord = ord($char);
        $al = $char == "\n" || ($ord >= 32 && $ord <= 127);
        $isAscii[$al] += 1;
        if (ctype_lower($prev)) {
            $mocking[ctype_upper($char)] += 1;
        }
        $prev = $char;
    }
    $asciiRatio = ($isAscii[false] / $isAscii[true]);
    $mockingRatio = ($mocking[true] / $mocking[false]);
    $ratio = $asciiRatio * $mockingRatio;
    return $ratio < 0.004;
}

function convertISBN10toISBN13(string $isbn10): string|false {
    // 1. Clean the ISBN-10 string (remove hyphens/spaces).
    $cleanedIsbn10 = preg_replace('/[ -]/', '', $isbn10);

    // 2. Initial length check.
    if (strlen($cleanedIsbn10) !== 10) {
        return false;
    }

    // 3. Strip the ISBN-10 check digit (the last character).
    // This gives us the first 9 digits of the identifier portion.
    $isbn9 = substr($cleanedIsbn10, 0, 9);

    // 4. Prepend "978" to create the 12-digit prefix.
    $isbn12 = "978" . $isbn9;

    $sum = 0;

    // 5. Calculate the weighted sum of the 12 digits (weights 1, 3, 1, 3, ...).
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$isbn12[$i];

        // Weight is 1 for odd positions (0, 2, 4, ...) and 3 for even positions (1, 3, 5, ...).
        $weight = ($i % 2 === 0) ? 1 : 3;
        $sum += $digit * $weight;
    }

    // 6. Calculate the check digit (d13).
    // The check digit is calculated as 10 minus the remainder of the sum divided by 10.
    // The second modulo 10 handles the case where the remainder is 0, resulting in a check digit of 0.
    $remainder = $sum % 10;
    $checkDigit = (10 - $remainder) % 10;

    // 7. Append the check digit.
    return $isbn12 . $checkDigit;
}

function atomic_file_put_contents($path, $data) {
    $tmp_path = $path . '.tmp';
    $res = file_put_contents($tmp_path, $data);
    if ($res === false) {
        return false;
    }
    return rename($tmp_path, $path);
}