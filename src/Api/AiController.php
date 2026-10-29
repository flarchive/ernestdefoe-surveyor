<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Ai\AnthropicProvider;
use Ernestdefoe\Surveyor\Ai\Describer;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Security\Secrets;
use Psr\Http\Message\ServerRequestInterface;

class AiController extends Controller
{
    protected array $actions = ['show', 'key', 'candidates', 'preview', 'apply', 'undo'];

    public function __construct(
        protected Describer $describer,
        protected Secrets $secrets,
        protected AnthropicProvider $anthropic,
        protected ApiLog $log,
    ) {
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        return [
            'enabled' => $this->describer->enabled(),
            'hasKey' => $this->secrets->has(Secrets::ANTHROPIC),
            'model' => $this->anthropic->model(),
            'usage' => $this->describer->usageThisMonth(),
            'today' => $this->log->today('ai'),
            'dailyCap' => $this->describer->dailyCap(),
            'monthlyTokens' => $this->describer->monthlyTokens(),
            'pausedUntil' => ($until = $this->log->pausedUntil('ai')) ? date(DATE_ATOM, $until) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function key(ServerRequestInterface $request): array
    {
        $key = trim((string) ($this->body($request)['key'] ?? ''));
        $rejected = false;

        if ($key === '') {
            $this->secrets->forget(Secrets::ANTHROPIC);
        } elseif (preg_match('/^sk-ant-[A-Za-z0-9_-]{20,300}$/', $key)) {
            $this->secrets->put(Secrets::ANTHROPIC, $key);
            $this->log->resume('ai');
        } else {
            $rejected = true;
        }

        return ['keyRejected' => $rejected] + $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function candidates(ServerRequestInterface $request): array
    {
        return ['data' => $this->describer->candidates($this->offset($request), (bool) $this->query($request, 'all', false))];
    }

    /** @return array<string, mixed> */
    protected function preview(ServerRequestInterface $request): array
    {
        return $this->describer->preview((int) ($this->body($request)['id'] ?? 0));
    }

    /** @return array<string, mixed> */
    protected function apply(ServerRequestInterface $request): array
    {
        $body = $this->body($request);

        return ['error' => $this->describer->apply((int) ($body['id'] ?? 0), isset($body['text']) ? (string) $body['text'] : null)];
    }

    /** @return array<string, mixed> */
    protected function undo(ServerRequestInterface $request): array
    {
        return ['error' => $this->describer->undo((int) ($this->body($request)['id'] ?? 0))];
    }
}
