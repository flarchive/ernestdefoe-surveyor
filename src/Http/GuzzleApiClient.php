<?php

namespace Ernestdefoe\Surveyor\Http;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Throwable;

class GuzzleApiClient implements ApiClient
{
    /** No outside answer is read past this. */
    private const MAX_BYTES = 8_000_000;

    private ?Client $client = null;

    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        try {
            $response = $this->client()->request($method, $url, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $body,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::STREAM => true,
            ]);

            $stream = $response->getBody();
            $text = '';
            while (! $stream->eof() && strlen($text) < self::MAX_BYTES) {
                $text .= $stream->read(65536);
            }
            $stream->close();

            $out = [];
            foreach ($response->getHeaders() as $name => $values) {
                $out[strtolower($name)] = implode(', ', $values);
            }

            return ['status' => $response->getStatusCode(), 'body' => $text, 'headers' => $out];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => mb_substr($e->getMessage(), 0, 200), 'headers' => []];
        }
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => 5,
        ]);
    }
}
