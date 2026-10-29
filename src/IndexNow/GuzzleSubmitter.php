<?php

namespace Ernestdefoe\Surveyor\IndexNow;

use Ernestdefoe\Surveyor\Http\GuzzleFetcher;
use Ernestdefoe\Surveyor\Site;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Throwable;

class GuzzleSubmitter implements Submitter
{
    public function __construct(
        protected Site $site,
    ) {
    }

    public function submit(array $payload): array
    {
        try {
            $response = (new Client())->post(self::ENDPOINT, [
                RequestOptions::JSON => $payload,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT => 15,
                RequestOptions::HEADERS => ['User-Agent' => GuzzleFetcher::userAgent($this->site)],
            ]);

            $body = trim(strip_tags(substr((string) $response->getBody(), 0, 2000)));

            return ['status' => $response->getStatusCode(), 'message' => $body === '' ? null : mb_substr($body, 0, 250)];
        } catch (Throwable $e) {
            return ['status' => 0, 'message' => mb_substr($e->getMessage(), 0, 250)];
        }
    }
}
