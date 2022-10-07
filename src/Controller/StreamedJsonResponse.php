<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * StreamedJsonResponse represents a streamed HTTP response for JSON.
 *
 * A StreamedJsonResponse uses a structure and generics to create an
 * efficient resource saving JSON response.
 *
 * It uses flush after a specified flush size to directly stream the data.
 *
 * @see flush()
 *
 * @author Alexander Schranz <alexander@sulu.io>
 *
 * Example usage:
 *
 * $response = new StreamedJsonResponse(
 *     // json structure with closures in it which generates a list of data
 *     [
 *         '_embedded' => [
 *             'articles' => (function (): \Generator { // any method or function returning a Generator
 *                  yield ['title' => 'Article 1'];
 *                  yield ['title' => 'Article 2'];
 *                  yield ['title' => 'Article 3'];
 *             })(),
 *         ],
 *     ],
 * );
 */
class StreamedJsonResponse extends StreamedResponse
{
    public const DEFAULT_ENCODING_OPTIONS = JsonResponse::DEFAULT_ENCODING_OPTIONS;
    private const PLACEHOLDER = '__symfony_json__';

    private int $encodingOptions = self::DEFAULT_ENCODING_OPTIONS;

    /**
     * @param mixed[]                        $data      JSON Data containing PHP generators which will be streamed as list of data
     * @param int                            $status    The HTTP status code (200 "OK" by default)
     * @param array<string, string|string[]> $headers   An array of HTTP headers
     * @param int                            $flushSize After every which item of a generator the flush function should be called
     */
    public function __construct(
        private readonly array $data,
        int $status = 200,
        array $headers = [],
        private int $flushSize = 100,
    ) {
        parent::__construct($this->stream(...), $status, $headers);

        if (!$this->headers->get('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }
    }

    private function stream(): void
    {
        $generators = [];
        $structure = $this->data;

        array_walk_recursive($structure, function (&$item) use (&$generators) {
            // generators should be used but for better DX all kind of Traversable are supported
            if ($item instanceof \Traversable && !$item instanceof \JsonSerializable) {
                $generators[] = $item;
                $item = self::PLACEHOLDER;
            } elseif (self::PLACEHOLDER === $item) {
                // if the placeholder is already in the structure it should be replaced with a new one that explode
                // works like expected for the structure
                $generators[] = $item;
            }
        });

        $jsonEncodingOptions = \JSON_THROW_ON_ERROR | $this->getEncodingOptions();
        $keyEncodingOptions = $jsonEncodingOptions & ~\JSON_NUMERIC_CHECK;
        $jsonParts = explode('"'.self::PLACEHOLDER.'"', json_encode($structure, $jsonEncodingOptions));

        foreach ($generators as $index => $generator) {
            // send first and between parts of the structure
            echo $jsonParts[$index];

            if (self::PLACEHOLDER === $generator) {
                // the placeholders already in the structure are rendered here
                echo json_encode(self::PLACEHOLDER, $jsonEncodingOptions);

                continue;
            }

            $count = 0;
            $startTag = '[';
            foreach ($generator as $key => $item) {
                if (0 === $count) {
                    // depending on the first elements key the generator is detected as a list or map
                    // we can not check for a whole list or map because that would hurt the performance
                    // of the streamed response which is the main goal of this response class
                    if ($key !== 0) {
                        $startTag = '{';
                    }

                    echo $startTag;
                } else {
                    // if not first element of the generic a separator is required between the elements
                    echo ',';
                }

                if ($startTag === '{') {
                    echo json_encode((string)$key, $keyEncodingOptions) . ':';
                }

                echo json_encode($item, $jsonEncodingOptions);
                ++$count;

                if (0 === $count % $this->flushSize) {
                    flush();
                }
            }

            echo($startTag === '[' ? ']' : '}');
        }

        // send last part of the structure
        echo $jsonParts[array_key_last($jsonParts)];
    }

    /**
     * Returns options used while encoding data to JSON.
     */
    public function getEncodingOptions(): int
    {
        return $this->encodingOptions;
    }

    /**
     * Sets options used while encoding data to JSON.
     *
     * @return $this
     */
    public function setEncodingOptions(int $encodingOptions): static
    {
        $this->encodingOptions = $encodingOptions;

        return $this;
    }

    /**
     * Returns the flush size.
     */
    public function getFlushSize(): int
    {
        return $this->flushSize;
    }

    /**
     * Sets the flush size.
     *
     * @return $this
     */
    public function setFlushSize(int $flushSize): static
    {
        $this->flushSize = $flushSize;

        return $this;
    }
}
