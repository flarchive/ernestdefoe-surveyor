<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Diagnostics\Checks;
use Psr\Http\Message\ServerRequestInterface;

class DiagnosticsController extends Controller
{
    protected array $actions = ['run'];

    public function __construct(
        protected Checks $checks,
    ) {
    }

    /** @return array<string, mixed> */
    protected function run(ServerRequestInterface $request): array
    {
        return ['data' => $this->checks->run()];
    }
}
