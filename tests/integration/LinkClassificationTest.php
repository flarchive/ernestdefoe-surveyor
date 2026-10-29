<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Crawl\Classifier;
use Ernestdefoe\Surveyor\Http\Page;
use PHPUnit\Framework\Attributes\Test;

class LinkClassificationTest extends SurveyorTestCase
{
    private function classify(Page $page, string $path): string
    {
        /** @var Classifier $classifier */
        $classifier = $this->make(Classifier::class);

        return $classifier->classify($page, $path);
    }

    #[Test]
    public function a_404_for_something_that_exists_is_members_only_not_broken(): void
    {
        $notFound = fn (string $path) => new Page('http://localhost'.$path, 404, ['content-type' => 'text/html'], '<html></html>');

        // Discussion 2 is private: a guest gets 404, but it is there.
        $this->assertSame(Classifier::RESTRICTED, $this->classify($notFound('/d/2-secret-garden-plans'), '/d/2-secret-garden-plans'));
        // Discussion 3 is hidden (deleted): gone for everyone who reads it.
        $this->assertSame(Classifier::BROKEN, $this->classify($notFound('/d/3'), '/d/3'));
        // Discussion 99 never existed.
        $this->assertSame(Classifier::BROKEN, $this->classify($notFound('/d/99-nope'), '/d/99-nope'));
        // A member who exists but whose profile guests cannot see.
        $this->assertSame(Classifier::RESTRICTED, $this->classify($notFound('/u/normal'), '/u/normal'));
        $this->assertSame(Classifier::BROKEN, $this->classify($notFound('/u/nobody'), '/u/nobody'));
        $this->assertSame(Classifier::BROKEN, $this->classify(new Page('http://localhost/x', 410), '/x'));
    }

    #[Test]
    public function forbidden_challenges_redirects_and_failures_are_told_apart(): void
    {
        $this->assertSame(Classifier::RESTRICTED, $this->classify(new Page('http://localhost/p', 403, [], 'Forbidden'), '/p'));
        $this->assertSame(Classifier::RESTRICTED, $this->classify(new Page('http://localhost/p', 401), '/p'));

        $this->assertSame(Classifier::CHALLENGE, $this->classify(new Page('http://localhost/p', 403, ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'], ''), '/p'));
        $this->assertSame(Classifier::CHALLENGE, $this->classify(new Page('http://localhost/p', 503, ['server' => 'cloudflare'], '<html><title>Just a moment...</title><script src="/cdn-cgi/challenge-platform/x.js"></script></html>'), '/p'));
        // A 200 that merely mentions a captcha is a normal page.
        $this->assertSame(Classifier::OK, $this->classify(new Page('http://localhost/p', 200, [], 'We use g-recaptcha on sign up'), '/p'));

        $this->assertSame(Classifier::REDIRECT, $this->classify(new Page('http://localhost/old', 200, [], '', 'http://localhost/new', [['status' => 301, 'location' => 'http://localhost/new']]), '/old'));
        $this->assertSame(Classifier::REDIRECT, $this->classify(new Page('http://localhost/old', 301, [], '', 'https://example.com/', [['status' => 301, 'location' => 'https://example.com/']]), '/old'));
        // A redirect that ends at a missing page is broken.
        $this->assertSame(Classifier::BROKEN, $this->classify(new Page('http://localhost/old', 404, [], '', 'http://localhost/gone', [['status' => 301, 'location' => 'http://localhost/gone']]), '/old'));

        $this->assertSame(Classifier::FAILED, $this->classify(new Page('http://localhost/p', 0, error: 'timeout'), '/p'));
        $this->assertSame(Classifier::FAILED, $this->classify(new Page('http://localhost/p', 500), '/p'));
        $this->assertSame(Classifier::OK, $this->classify(new Page('http://localhost/p', 200), '/p'));
    }
}
