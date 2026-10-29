<?php

namespace Ernestdefoe\Surveyor;

use Ernestdefoe\Surveyor\Http\Fetcher;
use Ernestdefoe\Surveyor\Http\GuzzleFetcher;
use Ernestdefoe\Surveyor\IndexNow\GuzzleSubmitter;
use Ernestdefoe\Surveyor\IndexNow\Submitter;
use Ernestdefoe\Surveyor\Rules\RuleSet;
use Flarum\Foundation\AbstractServiceProvider;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Nothing here is resolved on a forum page view: these are made only
        // by Surveyor's admin endpoints, its console commands and its listener.
        $this->container->singleton(Site::class);
        $this->container->singleton(Settings::class);
        $this->container->singleton(RuleSet::class);
        $this->container->bind(Fetcher::class, GuzzleFetcher::class);
        $this->container->bind(Submitter::class, GuzzleSubmitter::class);
        $this->container->bind(Http\ApiClient::class, Http\GuzzleApiClient::class);
        $this->container->bind(Ai\Provider::class, Ai\AnthropicProvider::class);
        $this->container->singleton(Security\Secrets::class);
    }
}
