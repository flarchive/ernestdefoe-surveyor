<?php

namespace Ernestdefoe\Surveyor\Http;

/**
 * Requests to outside services (Google, PageSpeed Insights, the AI
 * provider). Bound in the container so tests swap in a fake: no test ever
 * reaches the network.
 */
interface ApiClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     *         status 0 when the request itself failed (DNS, timeout, refused)
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array;
}
