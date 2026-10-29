<?php

namespace Ernestdefoe\Surveyor\IndexNow;

/** Sends one IndexNow request. Bound in the container so tests use a fake. */
interface Submitter
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /**
     * @param array{host: string, key: string, keyLocation: string, urlList: list<string>} $payload
     * @return array{status: int, message: string|null}
     */
    public function submit(array $payload): array;
}
