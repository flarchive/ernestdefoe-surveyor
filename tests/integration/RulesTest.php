<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Crawl\Eligibility;
use Ernestdefoe\Surveyor\Rules\Matcher;
use Ernestdefoe\Surveyor\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

class RulesTest extends SurveyorTestCase
{
    #[Test]
    public function exact_wildcard_and_regex_rules_match_paths(): void
    {
        $exact = (string) Matcher::compile('exact', '/t/staff/');
        $this->assertTrue(Matcher::matches($exact, '/t/staff'));
        $this->assertTrue(Matcher::matches($exact, '/T/Staff/'));
        $this->assertFalse(Matcher::matches($exact, '/t/staff-room'));

        $wild = (string) Matcher::compile('wildcard', '/t/staff*');
        $this->assertTrue(Matcher::matches($wild, '/t/staff'));
        $this->assertTrue(Matcher::matches($wild, '/t/staff/sub'));
        $this->assertFalse(Matcher::matches($wild, '/d/1'));

        $one = (string) Matcher::compile('wildcard', '/d/?-test');
        $this->assertTrue(Matcher::matches($one, '/d/1-test'));
        $this->assertFalse(Matcher::matches($one, '/d/12-test'));
        // Regex characters in a wildcard are literal.
        $this->assertFalse(Matcher::matches((string) Matcher::compile('wildcard', '/a.b'), '/axb'));

        $regex = (string) Matcher::compile('regex', '^/d/\d+-draft');
        $this->assertTrue(Matcher::matches($regex, '/d/12-draft-notes'));
        $this->assertFalse(Matcher::matches($regex, '/d/x-draft'));
        // A ~ in the pattern does not break out of the delimiter.
        $this->assertTrue(Matcher::matches((string) Matcher::compile('regex', '~user'), '/~user'));
    }

    #[Test]
    public function bad_and_dangerous_regexes_are_refused(): void
    {
        $this->assertNull(Matcher::compile('regex', '^/d/(\d+'));        // does not compile
        $this->assertNull(Matcher::compile('regex', '(a+)+$'));          // nested repeat
        $this->assertNull(Matcher::compile('regex', '^(\w+\s?)*$'));     // nested repeat
        $this->assertNull(Matcher::compile('regex', '(x{2,})*'));
        $this->assertNull(Matcher::compile('regex', str_repeat('a', 301)));
        $this->assertNull(Matcher::compile('regex', ''));
        $this->assertNull(Matcher::compile('nonsense', '/x'));

        // Even a pattern that slips through cannot hang: the backtracking budget ends the match.
        $slow = '~^(a|a)*b$~';
        $started = microtime(true);
        $this->assertFalse(Matcher::matches($slow, str_repeat('a', 40)));
        $this->assertLessThan(2, microtime(true) - $started);
    }

    #[Test]
    public function exclude_and_include_only_rules_shape_each_area(): void
    {
        $this->app();
        Rule::query()->insert([
            ['type' => 'wildcard', 'pattern' => '/t/test*', 'action' => 'exclude', 'scope' => 'both', 'enabled' => true],
            ['type' => 'exact', 'pattern' => '/d/5', 'action' => 'exclude', 'scope' => 'indexnow', 'enabled' => true],
            ['type' => 'regex', 'pattern' => '^/d/', 'action' => 'include', 'scope' => 'indexnow', 'enabled' => true],
            ['type' => 'exact', 'pattern' => '/d/6', 'action' => 'exclude', 'scope' => 'both', 'enabled' => false],
        ]);

        /** @var Eligibility $eligibility */
        $eligibility = $this->make(Eligibility::class);

        $this->assertSame('rule', $eligibility->reason('/t/test-area', null, 'audit'));
        $this->assertSame('rule', $eligibility->reason('/t/test-area', null, 'indexnow'));
        $this->assertNull($eligibility->reason('/d/5', null, 'audit'));
        $this->assertSame('rule', $eligibility->reason('/d/5', null, 'indexnow'));
        // "Include only" for IndexNow: anything that is not a discussion is out of IndexNow, not of the audit.
        $this->assertSame('rule', $eligibility->reason('/u/normal', null, 'indexnow'));
        $this->assertNull($eligibility->reason('/u/normal', null, 'audit'));
        $this->assertNull($eligibility->reason('/d/7', null, 'indexnow'));
        // A disabled rule does nothing.
        $this->assertNull($eligibility->reason('/d/6', null, 'audit'));
    }

    #[Test]
    public function the_api_validates_saves_and_tests_rules(): void
    {
        $bad = $this->api('POST', '/rules', ['type' => 'regex', 'pattern' => '(a+)+']);
        $this->assertSame(422, $bad->getStatusCode());

        $this->assertSame(422, $this->api('POST', '/rules', ['type' => 'wildcard', 'pattern' => 'no-slash'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/rules', ['type' => 'exact', 'pattern' => '/x', 'scope' => 'everything'])->getStatusCode());

        $created = $this->json($this->api('POST', '/rules', ['type' => 'wildcard', 'pattern' => '/t/staff*', 'note' => 'Staff only']));
        $this->assertSame('/t/staff*', $created['data']['pattern']);
        $this->assertSame('exclude', $created['data']['action']);

        $tested = $this->json($this->api('POST', '/rules/test', ['path' => 'http://localhost/t/staff-room']));
        $this->assertSame('/t/staff-room', $tested['path']);
        $this->assertSame([$created['data']['id']], $tested['rules']);
        $this->assertSame('rule', $tested['audit']);
        $this->assertTrue($this->json($this->api('POST', '/rules/test', ['path' => 'https://example.com/t/staff']))['offsite']);
        $this->assertSame('denied', $this->json($this->api('POST', '/rules/test', ['path' => '/settings']))['audit']);

        $updated = $this->json($this->api('PATCH', '/rules/'.$created['data']['id'], ['enabled' => false]));
        $this->assertFalse($updated['data']['enabled']);

        $this->api('DELETE', '/rules/'.$created['data']['id']);
        $this->assertSame([], $this->json($this->api('GET', '/rules'))['data']);
    }
}
