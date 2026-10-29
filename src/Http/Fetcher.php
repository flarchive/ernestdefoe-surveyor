<?php

namespace Ernestdefoe\Surveyor\Http;

/**
 * Fetches a page of this forum as a guest. Bound in the container so tests
 * can swap in a fake: no test ever touches the network.
 */
interface Fetcher
{
    /**
     * @param string $url an address Site::owns() accepted
     * @param int $maxBytes the body is cut off past this
     * @param int|null $timeout seconds for the whole request; null = the admin's setting
     */
    public function get(string $url, int $maxBytes = Page::MAX_BYTES, ?int $timeout = null): Page;
}
