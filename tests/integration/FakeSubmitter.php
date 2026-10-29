<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\IndexNow\Submitter;

class FakeSubmitter implements Submitter
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public int $status = 200;

    public function submit(array $payload): array
    {
        $this->payloads[] = $payload;

        return ['status' => $this->status, 'message' => null];
    }

    /** @return list<string> every address sent, in order */
    public function urls(): array
    {
        return array_merge([], ...array_map(fn ($p) => $p['urlList'], $this->payloads));
    }
}
