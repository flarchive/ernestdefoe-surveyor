<?php

namespace Ernestdefoe\Surveyor\Api;

use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every Surveyor endpoint is for administrators only. A controller serves
 * several routes; the last part of the route name picks the method
 * ('surveyor.links.recheck' calls recheck()).
 */
abstract class Controller implements RequestHandlerInterface
{
    /** @var list<string> */
    protected array $actions = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $action = Str::afterLast((string) $request->getAttribute('routeName'), '.');

        if (! in_array($action, $this->actions, true)) {
            throw new RouteNotFoundException();
        }

        $result = $this->{$action}($request);

        return $result instanceof ResponseInterface ? $result : new JsonResponse($result);
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function query(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        return Arr::get($request->getQueryParams(), $key, $default);
    }

    protected function id(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getAttribute('routeParameters', []), 'id');
    }

    protected function offset(ServerRequestInterface $request): int
    {
        return max(0, min(1_000_000, (int) $this->query($request, 'offset', 0)));
    }

    protected static function time(mixed $value): ?string
    {
        return $value ? \Carbon\Carbon::parse($value)->toIso8601String() : null;
    }
}
