<?php

namespace Ernestdefoe\Surveyor\IndexNow;

use Ernestdefoe\Surveyor\Settings;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** /{key}.txt — proves to IndexNow that the forum owns the key. */
class KeyController implements RequestHandlerInterface
{
    public function __construct(
        protected Settings $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $asked = (string) ($request->getAttribute('routeParameters')['key'] ?? '');
        $key = $this->settings->indexNowKey();

        if ($key === null || ! hash_equals($key, $asked)) {
            return new TextResponse('Not found', 404);
        }

        return new TextResponse($key, 200, ['Cache-Control' => 'public, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }
}
