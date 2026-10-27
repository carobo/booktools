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

    $system = 'You are an expert metadata extraction system for books.
    Provide the requested information about the book, given the context in the user prompt.
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

function ollamaMetadata($path, $metadata=[], $with_cover=true) {
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
        'edition' => '',
        'volume' => '',
        'year' => '',
        'language' => '',
    ];
    foreach ($examples as $key => $value) {
        if (!empty($metadata[$key])) {
            $examples[$key] = " (e.g. \"$metadata[$key]\")";
        }
    }

    $topics = libgenTopics();
    $topics_text = '"' . implode('", "', $topics) . '"';

    $prompt = "You are an expert metadata extraction system. Your task is to extract the document's metadata from the provided text. For all fields except language and category, use a literal copy of text found in the input, do not hallicinate or infer. The title and authors fields are most critical. If any requested value is not found or seems incorrect or illegible, omit it from the output. Return the output in the requested JSON format. If a value is all upper case, convert it to title case. Return a JSON object with these keys:

    title: title of the book, including the subtitle if the book has one$examples[title];
    authors: string with semicolon-separated list of author names, without titles or place names$examples[authors];
    publisher: name of the company that published the book$examples[publisher];
    edition: the revision or edition of the book, preferably as a number$examples[edition];
    volume: the volume of this book in a series, if available$examples[volume];
    year: the year this book was published$examples[year];
    language: the English name for the language this book is written in$examples[language].
    category: one of $topics_text

Below is the text from the first pages of the book. Extract the following fields literally from the text: title, authors, publisher, edition, volume, and year. Infer the value for language and category.

## File: $filename

```text
$text
```

Output a JSON with title, authors, publisher, edition, volume, year and language (all strings) for this book.
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
        return ollamaMetadata($path, $metadata, false);
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
    
    $prompt = "Below is metadata for a book. Determine which category this book falls in to. The categories are $topics_text. Omit an explanation, output only the best-fitting category.

```json
$metadata_json
```

Output the most fitting category for this book: $topics_text";

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
    // The \p{So} (Other_Symbol) category is key here, though it catches other symbols too.
    $text = preg_replace('/[\p{So}\p{Lo}\p{Po}&&[^a-zA-Z0-9\s]]/u', '', $text);

    // --- 3. Replace Remaining Non-ASCII/Non-Standard Characters ---
    // After normalization, remove anything that's still non-standard or might
    // confuse the tokenizer and force byte-level tokenization.
    // This pattern keeps basic letters, numbers, standard punctuation, and spaces.
    $text = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $text);

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
