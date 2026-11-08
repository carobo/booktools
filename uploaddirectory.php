<?php

require_once('booktools.php');

function done_with_file($path) {
    global $done_file;
    file_put_contents($done_file, basename($path) . "\n", FILE_APPEND);
    if (DELETE_AFTER_PROCESSING) unlink($path);
    unlock($path);
}

function lock($path) {
    $lockfile = "$path.lock";
    $contents = @file_get_contents($lockfile);
    if ($contents === false) {
        file_put_contents($lockfile, getmypid());
        return file_get_contents($lockfile) == getmypid();
    } else if ($contents == getmypid()) {
        return true;
    } else {
        return false;
    }
}

function unlock($path) {
    $lockfile = "$path.lock";
    if (@file_get_contents($lockfile) == getmypid()) {
        unlink($lockfile);
    }
}

$dir = $argv[1];
echo "Uploading directory $dir.\n";

$done_file = "$dir/uploaded.txt";
touch($done_file);
$done_paths = array_map('trim', file($done_file));

$metadata_json = "$dir/metadata.json";

$paths = recursive_glob($dir, [
    '*.pdf',
    '*.epub',
    '*.djvu',
    '*.cbz',
    '*.mobi',
    '*.azw',
    '*.azw3',
    '*.lit',
    '*.fb2',
]);

$done = 0;

$seen_isbns = [];
$seen_md5 = [];

foreach ($done_paths as $path) {
    if (preg_match('~^([0-9A-Fa-f]{32})\.~', $path, $matches)) {
        $seen_md5[] = $matches[1];
    }
}

if (file_exists($metadata_json)) {
    $data = json_decode('[' . file_get_contents($metadata_json) . 'null]', true);
    if (empty($data)) {
        echo "$metadata_json corrupt.\n";
    } else {
        foreach ($data as $book) {
            if ($book !== null) {
                if (!empty($book['_path'])) {
                    $path = $book['_path'];
                    if (preg_match('~/([0-9A-Fa-f]{32})\.~', $path, $matches)) {
                        $seen_md5[] = $matches[1];
                    }
                }
            }
        }
    }
}

foreach ($paths as $path) {
    $done += 1;

    if (!is_file($path)) continue;
    // if (!lock($path)) continue;

    if (in_array(basename($path), $done_paths)) {
        done_with_file($path);
        continue;
    }
    sleep(1);
    echo "$done. $path\n";

    $metadata = [
        'isbn' => [],
        '_path' => $path,
    ];

    $pages = numberOfPages($path);
    $text = fileToText($path);
    $doi = getDoiFromFile($path);
    $issns = getIssnFromFile($path);

    if (!empty($doi)) {
        $metadata['doi'] = $doi;
    }
    if (!empty($issns)) {
        $metadata['issn'] = $issns;
    }

    $journal_issue_metadata = getJournalIssueMetadataFromFile($path, $text, $issns);
    if ($journal_issue_metadata !== null) {
        echo "Detected a complete journal issue.\n";
        $metadata = mergeMetadata($metadata, $journal_issue_metadata);
    }

    if ($pages !== null && $pages < 8) {
        echo "File has only ~$pages pages, skipping... ";
        done_with_file($path);
        continue;
    }

    if ($pages !== null && $pages < 24) {
        echo "File has only ~$pages pages, checking whether it is an article... ";
        $document_type = ollamaDocumentType($path, $metadata);
        if (isArticleDocumentType($document_type)) {
            echo "$document_type.\n";
            $metadata['document_type'] = $document_type;
        } else {
            echo "no" . ($document_type ? " ($document_type)" : "") . ".\n";
            done_with_file($path);
            continue;
        }
    }

    if (empty($text)) {
        echo "Not legible.\n";
        done_with_file($path);
        continue;
    }

    if (preg_match('~/([0-9A-Fa-f]{32})\.~', $path, $matches)) {
        $md5 = $matches[1];
    } else {
        $md5 = md5($path);
    }

    if (in_array($md5, $seen_md5)) {
        echo "MD5 seen in earlier file.\n";
        done_with_file($path);
        continue;
    }
    $seen_md5[] = $md5;
    if (hasMD5($md5)) {
        echo "MD5 already in Anna's Archive.\n";
        done_with_file($path);
        continue;
    }

    try {
        $isbns = getIsbnFromFile($path);
    } catch (Exception $e) {
        var_dump($e);
        $isbns = [];
    }

    if (empty($isbns)) {
        echo "Could not determine ISBN.\n";
        if (!UPLOAD_WITHOUT_ISBN) {
            done_with_file($path);
            continue;
        }
    } else {
        $isRequested = false;
        foreach ($isbns as $isbn) {
            if (isRequested($isbn)) {
                $isRequested = true;
                echo "ISBN $isbn requested in zlib.\n";
            }
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        foreach ($isbns as $isbn) {
            $isbn_ext = "$isbn.$extension";
            try {
                if (in_array($isbn_ext, $seen_isbns)) {
                    echo "ISBN $isbn_ext seen in earlier file.\n";
                    done_with_file($path);
                    continue 2;
                }
                if (!$isRequested && zlibHasIsbn($isbn)) {
                    echo "ISBN $isbn already in zlib.\n";
                    done_with_file($path);
                    continue 2;
                }
                if (!$isRequested && inAnnasArchive($isbn)) {
                    echo "ISBN $isbn already in Anna's Archive.\n";
                    done_with_file($path);
                    continue 2;
                }
            } catch (Exception $e) {
                var_dump($e);
            }

            $seen_isbns[] = $isbn_ext;
        }
    }

    if (str_ends_with(strtolower($path), '.epub') && (empty($metadata['title']) || empty($metadata['authors']))) {
        $epub_metadata = epubMetadata($path);
        $metadata = mergeMetadata($metadata, $epub_metadata);
    }

    $detected_document_type = normalizeDocumentType($metadata['document_type'] ?? null);

    $needs_llm = empty($metadata['title']);
    if (in_array($detected_document_type, ['journal', 'magazine'], true)) {
        $needs_llm = $needs_llm || empty($metadata['series']);
    } else {
        $needs_llm = $needs_llm || empty($metadata['authors']) || empty($metadata['language']);
    }

    if ($needs_llm) {
        echo "Asking LLM for metadata... ";
        $ollamaMetadata = ollamaMetadata($path, $metadata, true, $metadata['document_type'] ?? null);
        if ($ollamaMetadata !== null) {
            echo "success.\n";
            $metadata = mergeMetadata($metadata, $ollamaMetadata);
        } else {
            echo "failed, skipping.\n";
            unlock($path);
            continue;
        }
    }

    $metadata['isbn'] = mergeIsbns($metadata['isbn'] ?? [], $isbns);

    $document_type = normalizeDocumentType($metadata['document_type'] ?? null)
        ?? normalizeDocumentType($metadata['content_type'] ?? null)
        ?? $detected_document_type
        ?? ((empty($metadata['isbn']) && !empty($doi)) ? 'article' : 'book');
    if (empty($document_type)) {
        $document_type = 'book';
    }
    if (isArticleDocumentType($detected_document_type) && !isArticleDocumentType($document_type)) {
        $document_type = $detected_document_type;
    }
    $metadata['document_type'] = $document_type;

    $metadata['doi'] = cleanDoi($metadata['doi'] ?? null) ?? $doi;
    $metadata['issn'] = cleanIssns($metadata['issn'] ?? $issns);
    if (empty($metadata['issn']) && !empty($issns)) {
        $metadata['issn'] = $issns;
    }

    if (empty($metadata['series'])) {
        foreach (['journal', 'publication', 'periodical'] as $key) {
            if (!empty($metadata[$key])) {
                $metadata['series'] = $metadata[$key];
                break;
            }
        }
    }

    if (isArticleDocumentType($document_type) && !empty($metadata['issn'])) {
        $crossref_journal = getJournalTitleFromIssn($metadata['issn']);
        if (!empty($crossref_journal)) {
            echo "Using journal title from ISSN: $crossref_journal\n";
            $metadata['series'] = $crossref_journal;
        }
    }

    if (isArticleDocumentType($document_type) && empty($metadata['series'])) {
        echo "Asking LLM for journal name... ";
        $series = ollamaSeries($path, $metadata);
        if (!empty($series)) {
            echo "$series.\n";
            $metadata['series'] = $series;
        } else {
            echo "failed.\n";
        }
    }

    if (($document_type === 'journal' || $document_type === 'magazine') && !empty($metadata['volume']) && !empty($metadata['issue'])) {
        $normalized_title = lgpNormalizeSeriesTitle($metadata['title'] ?? '');
        $normalized_series = lgpNormalizeSeriesTitle($metadata['series'] ?? '');
        $normalized_publisher = lgpNormalizeSeriesTitle($metadata['publisher'] ?? '');
        $title_is_journal_name = $normalized_title !== '' && $normalized_series !== ''
            && ($normalized_title === $normalized_series
                || similar_text($normalized_title, $normalized_series) / strlen($normalized_title) > 0.75);
        if (empty($metadata['title']) || $title_is_journal_name || ($normalized_title !== '' && $normalized_title === $normalized_publisher)) {
            $metadata['title'] = "Volume {$metadata['volume']}, Issue {$metadata['issue']}";
            if (!empty($metadata['year'])) {
                $metadata['title'] .= ", {$metadata['year']}";
            }
            echo "Using issue title: {$metadata['title']}\n";
        }
    }

    if (isArticleDocumentType($document_type)) {
        $metadata['content_type'] = 'article';
        if ($document_type === 'magazine') {
            $metadata['pre_lg_topic'] = 'm';
            $metadata['libgen_type'] = 'm';
        } else {
            if (empty($metadata['pre_lg_topic']) || !in_array($metadata['pre_lg_topic'], ['a', 'm'], true)) {
                $metadata['pre_lg_topic'] = 'a';
            }
            $metadata['libgen_type'] = $document_type === 'journal' ? 'ji' : 'a';
        }
        if ($document_type === 'journal' || $document_type === 'magazine') {
            // A complete issue may contain a table of contents with article
            // DOIs and page ranges. Those do not describe the issue itself.
            unset($metadata['doi'], $metadata['first_page'], $metadata['last_page']);
        }
        if (empty($metadata['series'])) {
            echo "Warning: no journal/series name found for this article.\n";
        }
    }

    if (empty($metadata['language'])) {
        $metadata['language'] = guessLanguage($text);
    }

    if (empty($metadata['pre_lg_topic'])) {
        if (!empty($metadata['category']) && in_array($metadata['category'], libgenTopics())) {
            $topics_lookup = array_flip(libgenTopics());
            $metadata['pre_lg_topic'] = $topics_lookup[$metadata['category']];
        } else {
            if (defined('LIBGEN_UPLOAD') && LIBGEN_UPLOAD)
                $metadata['pre_lg_topic'] = ollamaLibgenTopic($path, $metadata);
        }
    }

    if (empty($metadata['title'])) {
        echo "Could not determine title.\n";
        done_with_file($path);
        continue;
    }

    echo '"' . $metadata['title'] . '" by ' . $metadata['authors'] . "\n";

    file_put_contents($metadata_json, json_encode($metadata, JSON_PRETTY_PRINT) . ",\n", FILE_APPEND);

    try {
        $res = upload($path, $metadata);
    } catch (UploadException $e) {
        var_dump($e);
    }

    if (!$res || filesize($path) >= 100000000) {
        if (reduce_file_size($path)) {
            try {
                upload($path, $metadata);
            } catch (UploadException $e) {
                var_dump($e);
            }
        }
    }


    if ($res) done_with_file($path);
}
