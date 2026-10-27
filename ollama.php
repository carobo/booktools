<?php

require_once('lib.php');

function ollamaQuery($prompt, $images=[]) {
    $userContent = $prompt;
    if (!empty($images)) {
        $userContent = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $image) {
            $mime = mime_content_type($image);
            $base64 = base64_encode(file_get_contents($image));
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:$mime;base64,$base64"
                ]
            ];
        }
    }

    $system = 'You are an expert metadata extraction system for books, scientific articles, journals and magazines.
    Provide the requested information about the document, given the context in the user prompt.
    The response will be read by a computer, not by a human, so respond with either JSON or keywords.';

    $ollama_url = 'http://localhost:9931/v1/chat/completions';
    $data = [
        'model' => 'ministral-3:14b',
        'keep_alive' => -1,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ],
        'images' => $images,
        'stream' => false,
        'options' => [
            'temperature' => 0.1,
            // 'top_p' => 0.1,
        ],
    ];

    static $ch = curl_init($ollama_url);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the request
    $response = curl_exec($ch);
    $api_response = json_decode($response, true);
    return $api_response['choices'][0]['message']['content'];
}

function ollamaMetadata($path, $metadata=[], $with_cover=true, $document_type=null) {
    $text = fileToText($path);
    $text = cleanup_for_ollama($text);

    $filename = basename($path);

    $images = [];
    if ($with_cover) {
        $cover = fileCoverImage($path);
        if ($cover) {
            $images[] = $cover;
        }
    }

    $examples = [
        'title' => '',
        'authors' => '',
        'publisher' => '',
        'series' => '',
        'edition' => '',
        'volume' => '',
        'issue' => '',
        'first_page' => '',
        'last_page' => '',
        'doi' => '',
        'issn' => '',
        'year' => '',
        'language' => '',
    ];
    foreach ($examples as $key => $value) {
        if (!empty($metadata[$key])) {
            $example = $metadata[$key];
            if (is_array($example)) {
                $example = implode(', ', $example);
            }
            $examples[$key] = " (e.g. \"$example\")";
        }
    }

    $topics = libgenTopics();
    $topics_text = '"' . implode('", "', $topics) . '"';
    $type_hint = '';
    if (!empty($document_type)) {
        $type_hint = " This document has already been classified as \"$document_type\".\n";
    }

    $prompt = "You are an expert metadata extraction system. Your task is to extract the document's metadata from the provided text. For all fields except document_type, language and category, use a literal copy of text found in the input, do not hallucinate or infer. The title and authors fields are most critical. If any requested value is not found or seems incorrect or illegible, omit it from the output. Return the output in the requested JSON format. If a value is all upper case, convert it to title case. Return a JSON object with these keys:
$type_hint
    document_type: one of \"book\", \"article\", \"journal\", \"magazine\". Use \"article\" for a single scientific or scholarly article/paper. Use \"journal\" for a complete issue or volume of a journal. Use \"magazine\" for a complete issue of a magazine or a popular periodical.
    title: title of the book or article, including the subtitle if it has one. For a complete journal or magazine issue, use a descriptive title such as \"Volume 11, Number 1\" if the issue has no separate title$examples[title];
    authors: string with semicolon-separated list of author names, without titles or place names$examples[authors];
    series: name of the journal, magazine or book series the document belongs to$examples[series];
    volume: the volume of this document in a series, if available$examples[volume];
    issue: the issue or number within the volume, if available$examples[issue];
    edition: the revision or edition of the book, preferably as a number$examples[edition];
    first_page: the first page number of an article in the journal$examples[first_page];
    last_page: the last page number of an article in the journal$examples[last_page];
    doi: DOI of the article, if present$examples[doi];
    issn: ISSN of the journal or magazine, if present$examples[issn];
    publisher: name of the company that published the book or journal$examples[publisher];
    year: the year this document was published$examples[year];
    language: the English name for the language this document is written in$examples[language].
    category: one of $topics_text

Below is the text from the first pages of the document. Extract the following fields literally from the text: title, authors, series, volume, issue, edition, first_page, last_page, doi, issn, publisher and year. Infer the values for document_type, language and category.

## File: $filename

```text
$text
```

Output a JSON object with document_type, title, authors, series, volume, issue, edition, first_page, last_page, doi, issn, publisher, year, language and category (all strings) for this document.
    ";

    $response = ollamaQuery($prompt, $images);

    if (preg_match('~{.*}~s', $response, $matches)) {
        $model_response = json_decode($matches[0], true);
        if (is_array($model_response)) {
            foreach ($model_response as $key => $value) {
                if (is_array($value)) {
                    $string_value = @implode('; ', $value);
                    if (str_contains($string_value, 'Array')) {
                        $model_response[$key] = null;
                    } else {
                        $model_response[$key] = $string_value;
                    }
                }
            }
        }

        return $model_response;
    }

    if ($with_cover && !empty($images)) {
        // Try again without cover image
        echo "Failed, trying again without cover image... ";
        return ollamaMetadata($path, $metadata, false, $document_type);
    }

    return null;
}

function ollamaIsbns($path) {
    $text = fileToText($path);
    $text = cleanup_for_ollama($text);

    $filename = basename($path);

    $prompt = "You are an expert metadata extraction system. Your task is to extract the document's ISBN from the provided text. The text is part from a book. It lists both the ISBNs for the book itself, as well as ISBNs for related works. Return only the ISBNs for the book itself, not for related works. Use a literal copy of text found in the input, do not hallicinate or infer. Return the output in a JSON array. If no ISBN can be found, return an empty array.

Below is the text from the first pages of a book. Extract the ISBNs that apply to this book and leave out ISBNs that apply to other books.

## File: $filename

```text
$text
```

Output only the ISBNs that apply to this book.";

    $response = ollamaQuery($prompt);

    if (preg_match('~\[.*\]~s', $response, $matches)) {
        $model_response = json_decode($matches[0], true);
        return $model_response;
    }
    return grepIsbns($response);
}


function ollamaLibgenTopic($path, $metadata) {
    $metadata_json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    $topics = libgenTopics();
    $topics_text = '"' . implode('", "', $topics) . '"';
    
    $prompt = "Below is metadata for a document. Determine which category this document falls in to. The categories are $topics_text. Omit an explanation, output only the best-fitting category.

```json
$metadata_json
```

Output the most fitting category for this document: $topics_text";

    $response = ollamaQuery($prompt);

    uasort($topics, function ($a, $b) { return strlen($b) <=> strlen($a); });
    foreach ($topics as $key => $value) {
        if (str_contains($response, $value)) {
            return $key;
        }
    }
    return null;
}

/**
 * Asks the LLM whether a short document is an article/journal, or something
 * else (a presentation, balance sheet, report, ...).
 *
 * @return string|null One of book, article, journal, magazine, other, or null.
 */
function ollamaDocumentType($path, $metadata=[]) {
    $text = fileToText($path);
    $text = cleanup_for_ollama($text);

    $images = [];
    $cover = fileCoverImage($path);
    if ($cover) {
        $images[] = $cover;
    }

    $metadata_json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $filename = basename($path);

    $prompt = "Classify the document below. Choose exactly one of these values:
- \"book\": a book, ebook, book chapter or monograph.
- \"article\": a single scientific, scholarly or technical article/paper published in a journal, magazine or proceedings.
- \"journal\": a complete issue or volume of an academic/scientific journal (containing several articles).
- \"magazine\": a complete issue of a magazine, newsletter or other popular periodical.
- \"other\": anything else, such as a presentation, slides, a balance sheet, a spreadsheet, a form, a catalogue, or a report that is not a published article.

Use both the visual first page and the text. A document with an abstract, keywords, an introduction, a DOI or an ISSN in a journal header is usually an article or journal. A short PDF that is mostly slides, tables or financial figures is \"other\".

Known metadata:
```json
$metadata_json
```

## File: $filename

```text
$text
```

Output only a JSON object like {\"document_type\": \"article\"}.";

    $response = ollamaQuery($prompt, $images);

    if (preg_match('~{.*}~s', $response, $matches)) {
        $model_response = json_decode($matches[0], true);
        if (is_array($model_response)) {
            $type = normalizeDocumentType($model_response['document_type'] ?? null);
            if ($type) {
                return $type;
            }
        }
    }

    return normalizeDocumentType($response);
}

/**
 * Ask the LLM to identify the journal/series when it was not printed in the
 * document itself. Returns null when the model is not reasonably sure.
 */
function ollamaSeries($path, $metadata=[]) {
    $text = fileToText($path);
    $text = cleanup_for_ollama($text);
    if (strlen($text) > 4000) {
        $text = substr($text, 0, 4000);
    }

    $metadata_json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $filename = basename($path);

    $prompt = "The following document is a journal article or a journal/magazine issue. The name of the journal or series was not found in its text. Identify the journal or series it was published in using the title, authors, filename and path.

Only answer if you are reasonably confident. If you are not reasonably sure, output exactly: null
Output only the journal or series name, without quotes, explanations or markdown.

Known metadata:
```json
$metadata_json
```

## File: $filename
## Path: $path

```text
$text
```";

    $response = trim(ollamaQuery($prompt));
    $response = trim(explode("\n", $response)[0]);
    $response = preg_replace('~^(journal|series)\s*[:=]\s*~i', '', $response);
    $response = trim($response, " \t\n\r\0\x0B\"'`");
    if ($response === '' || strtolower($response) === 'null' || str_contains(strtolower($response), 'not sure')) {
        return null;
    }
    if (preg_match('~^(.+?)\s*\((?:[A-Z]{2,})\)$~', $response, $matches)) {
        $response = trim($matches[1]);
    }
    return $response;
}

/**
 * Prepares a string for efficient LLM tokenization by removing or normalizing
 * problematic characters like emojis and complex non-ASCII symbols.
 *
 * @param string $text The raw input string, potentially containing strange characters.
 * @return string The cleaned, LLM-ready string.
 */
function cleanup_for_ollama(string $text): string
{
    if (strlen($text) >= 6000) {
        $text = substr($text, 0, 4000) . ' ... ' . substr($text, -1000, 1000);
    }
    // --- 1. Remove Emojis ---
    // Emojis often consume 4+ tokens each. This pattern handles most Unicode emojis.
    $text = preg_replace('/[\p{So}\p{Cs}\p{Co}]/u', '', $text);

    // --- 3. Replace Remaining Non-ASCII/Non-Standard Characters ---
    // After normalization, remove anything that's still non-standard or might
    // confuse the tokenizer and force byte-level tokenization.
    // Keep letters, numbers, marks, standard punctuation, and spaces.
    $text = preg_replace('/[^\p{L}\p{N}\p{M}\p{P}\s]/u', '', $text);

    // Reduce periods
    $text = preg_replace('/(\. ?)+/', '\1', $text);

    // Replace ^L
    $text = str_replace("\x0c", "\n", $text);

    // --- 4. Normalize and Trim Whitespace ---
    // Collapses multiple spaces/newlines into a single space, and trims leading/trailing space.
    // Excess whitespace can sometimes lead to extra tokens.
    $text = preg_replace('/(\s)+/', '\1', $text);
    $text = trim($text);

    return $text;
}
