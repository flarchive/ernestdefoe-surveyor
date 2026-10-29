<?php

namespace Ernestdefoe\Surveyor\Http;

/** What came back for one address, after any redirects on the forum itself. */
final class Page
{
    /** 2 MB of HTML is far beyond any forum page; the rest is not read. */
    public const MAX_BYTES = 2_000_000;

    /**
     * @param array<string, string> $headers final response, names in lower case
     * @param list<array{status: int, location: string}> $redirects every hop followed (or refused)
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $finalUrl = '',
        public readonly array $redirects = [],
        public readonly int $ms = 0,
        public readonly int $bytes = 0,
        public readonly ?string $error = null,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isHtml(): bool
    {
        $type = strtolower($this->header('content-type'));

        return $type === '' || str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
