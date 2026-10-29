<?php

namespace Ernestdefoe\Surveyor\Ai;

/** Writes a meta description. Anthropic first; another provider only needs this interface. */
interface Provider
{
    /**
     * @return array{text: string|null, error: string|null, status: int, input: int, output: int}
     */
    public function describe(string $title, string $content, string $locale, int $maxChars): array;
}
