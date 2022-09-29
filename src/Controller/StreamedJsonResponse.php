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

    private int $encodingOptions = self::DEFAULT_ENCODING_OPTIONS;

    /**
     * @param mixed[]                        $data       Structure of the JSON containing closures in it which generates a list of data
     * @param int                            $status     The HTTP status code (200 "OK" by default)
     * @param array<string, string|string[]> $headers    An array of HTTP headers
     * @param int                            $flushSize  After every which item of a generator the flush function should be called
     */
    public function __construct(
        private readonly array $data,
        int $status = 200,
        array $headers = [],
        private int $flushSize = 500,
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

        \array_walk_recursive($structure, function (&$item, $key)  use (&$generators)
        {
            if ($item instanceof \Generator) {
                // using uniqid to avoid conflict with eventually other data in the structure
                $placeholder = \uniqid('__placeholder_', true);
                $generators[$placeholder] = $item;

                $item = $placeholder;
            }
        });

        $keys = array_keys($generators);

        $jsonEncodingOptions = \JSON_THROW_ON_ERROR | $this->getEncodingOptions();
        $structureText = json_encode($structure, $jsonEncodingOptions);

        foreach ($keys as $key) {
            [$start, $end] = explode('"'.$key.'"', $structureText, 2);

            // send first and between parts of the structure
            echo $start;

            $count = 0;
            echo '[';
            foreach ($generators[$key] as $array) {
                if (0 !== $count) {
                    // if not first element of the generic a separator is required between the elements
                    echo ',';
                }

                echo json_encode($array, $jsonEncodingOptions);
                ++$count;

                if (0 === $count % $this->flushSize) {
                    flush();
                }
            }
            echo ']';

            $structureText = $end;
        }

        echo $structureText; // send the after part of the structure as last
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
