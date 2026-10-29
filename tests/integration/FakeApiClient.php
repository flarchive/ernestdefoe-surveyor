<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Http\ApiClient;

/**
 * Answers outside calls from a list of (address fragment => answer). The
 * first rule whose fragment is in the address answers; a rule given as a
 * list answers in turn, then repeats its last answer. Every call is kept.
 */
class FakeApiClient implements ApiClient
{
    /** @var array<string, list<array{status: int, body: string|array<mixed>, headers?: array<string, string>}>> */
    public array $rules = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $calls = [];

    /** @param array{status: int, body: string|array<mixed>, headers?: array<string, string>}|list<array{status: int, body: string|array<mixed>}> $answers */
    public function on(string $fragment, array $answers): void
    {
        $this->rules[$fragment] = isset($answers['status']) ? [$answers] : $answers;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->rules as $fragment => &$answers) {
            if (str_contains($url, $fragment)) {
                $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];

                return [
                    'status' => $answer['status'],
                    'body' => is_array($answer['body']) ? (string) json_encode($answer['body']) : $answer['body'],
                    'headers' => $answer['headers'] ?? [],
                ];
            }
        }

        return ['status' => 404, 'body' => '{}', 'headers' => []];
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public function to(string $fragment): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_contains($c['url'], $fragment)));
    }
}
