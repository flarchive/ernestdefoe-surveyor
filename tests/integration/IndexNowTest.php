<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\IndexNow\Sender;
use Ernestdefoe\Surveyor\Settings;
use PHPUnit\Framework\Attributes\Test;

class IndexNowTest extends SurveyorTestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private function on(): void
    {
        $this->setting(Settings::key('indexnow_enabled'), '1');
        $this->setting(Settings::key('indexnow_key'), self::KEY);
    }

    private function reply(int $discussionId, int $as = 1): void
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['attributes' => ['content' => 'A reply about roses'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussionId]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 300));
    }

    private function queued(): array
    {
        return $this->database()->table('surveyor_indexnow_queue')->where('pending', true)->pluck('discussion_id')->map(fn ($id) => (int) $id)->all();
    }

    private function sendQueue(): int
    {
        return $this->make(Sender::class)->sendQueue();
    }

    #[Test]
    public function nothing_is_queued_while_indexnow_is_off(): void
    {
        $this->reply(1);

        $this->assertSame([], $this->queued());
    }

    #[Test]
    public function a_reply_queues_its_discussion_once_and_the_scheduler_sends_it(): void
    {
        $this->on();
        $this->reply(1);
        $this->reply(1);

        $this->assertSame([1], $this->queued());

        $this->fakes();
        $this->assertSame(1, $this->sendQueue());
        $this->assertCount(1, $this->submitter->payloads);

        $payload = $this->submitter->payloads[0];
        $this->assertSame('localhost', $payload['host']);
        $this->assertSame(self::KEY, $payload['key']);
        $this->assertSame('http://localhost/'.self::KEY.'.txt', $payload['keyLocation']);
        $this->assertSame(['http://localhost/d/1-welcome-to-the-garden-club'], $payload['urlList']);
        $this->assertSame([], $this->queued());
        $this->assertSame(1, $this->database()->table('surveyor_indexnow_log')->count());
    }

    #[Test]
    public function a_busy_discussion_is_sent_at_most_once_an_hour(): void
    {
        $this->on();
        $this->fakes();

        $this->reply(1);
        $this->sendQueue();
        $this->reply(1);
        $this->assertSame([1], $this->queued());

        // Within the hour: held back.
        $this->assertSame(0, $this->sendQueue());
        $this->assertCount(1, $this->submitter->payloads);

        // An hour later: sent again.
        $this->database()->table('surveyor_indexnow_queue')->update(['submitted_at' => Carbon::now()->subMinutes(61)]);
        $this->assertSame(1, $this->sendQueue());
        $this->assertCount(2, $this->submitter->payloads);
    }

    #[Test]
    public function private_and_hidden_discussions_are_never_revealed(): void
    {
        $this->on();
        $this->fakes();
        $listener = $this->make(\Ernestdefoe\Surveyor\IndexNow\Listener::class);

        // Private (2) and hidden (3), never sent before: dropped without a request.
        $listener->touch(2);
        $listener->touch(3);
        $this->assertSame(0, $this->sendQueue());
        $this->assertSame([], $this->submitter->payloads);
        $this->assertSame(0, $this->database()->table('surveyor_indexnow_queue')->count());

        // A public discussion that was sent, then hidden: its old address goes once more, so engines drop it.
        $listener->touch(1);
        $this->sendQueue();
        $this->database()->table('discussions')->where('id', 1)->update(['hidden_at' => Carbon::now()]);
        $this->database()->table('surveyor_indexnow_queue')->update(['submitted_at' => Carbon::now()->subHours(2)]);
        $listener->touch(1);
        $this->sendQueue();

        $this->assertSame(['http://localhost/d/1-welcome-to-the-garden-club', 'http://localhost/d/1-welcome-to-the-garden-club'], $this->submitter->urls());
        $this->assertNotContains('http://localhost/d/2-secret-garden-plans', $this->submitter->urls());
    }

    #[Test]
    public function a_refusal_pauses_sending_and_keeps_the_queue(): void
    {
        $this->on();
        $this->fakes();
        $this->submitter->status = 429;
        $this->make(\Ernestdefoe\Surveyor\IndexNow\Listener::class)->touch(1);

        $this->assertSame(0, $this->sendQueue());
        $this->assertSame([1], $this->queued());
        $this->assertNotNull($this->make(Sender::class)->backoffUntil());

        // Paused: no second request.
        $this->submitter->status = 200;
        $this->sendQueue();
        $this->assertCount(1, $this->submitter->payloads);
    }

    #[Test]
    public function the_key_file_answers_only_for_the_real_key(): void
    {
        $this->on();

        $ok = $this->send($this->request('GET', '/'.self::KEY.'.txt'));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(self::KEY, (string) $ok->getBody());

        $this->assertSame(404, $this->send($this->request('GET', '/ffffffffffffffffffffffffffffffff.txt'))->getStatusCode());
    }

    #[Test]
    public function turning_it_on_makes_a_random_key_and_manual_sends_stay_on_site(): void
    {
        $shown = $this->json($this->api('POST', '/indexnow/enable'));
        $this->assertTrue($shown['enabled']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $shown['key']);

        $again = $this->json($this->api('POST', '/indexnow/key'));
        $this->assertNotSame($shown['key'], $again['key']);

        $this->fakes();
        $result = $this->json($this->api('POST', '/indexnow/submit', ['urls' => "http://localhost/d/1\nhttps://example.com/x\nhttp://localhost/settings\n"]));
        $this->assertSame(['http://localhost/d/1'], $result['sent']);
        $this->assertCount(2, $result['refused']);
        $this->assertSame(['http://localhost/d/1'], $this->submitter->urls());

        $off = $this->json($this->api('POST', '/indexnow/disable'));
        $this->assertFalse($off['enabled']);
    }
}
