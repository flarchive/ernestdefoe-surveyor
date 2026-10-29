<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Ai\Describer;
use Ernestdefoe\Surveyor\Settings;
use FoF\Seo\SeoMeta\SeoMeta;
use PHPUnit\Framework\Attributes\Test;

class AiDescriptionsTest extends SurveyorTestCase
{
    private const KEY = 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789';

    private function on(): void
    {
        $this->setting(Settings::key('ai_enabled'), '1');
        $this->fakes();
        $this->api('POST', '/ai/key', ['key' => self::KEY]);
    }

    private static function answer(string $text, int $in = 400, int $out = 40, string $stop = 'end_turn'): array
    {
        return ['status' => 200, 'body' => [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ]];
    }

    #[Test]
    public function nothing_is_sent_while_it_is_off(): void
    {
        $this->fakes();
        $this->api('POST', '/ai/key', ['key' => self::KEY]);

        $this->assertSame('off', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertSame([], $this->http->to('anthropic'));
    }

    #[Test]
    public function a_preview_calls_the_messages_api_with_tight_limits(): void
    {
        $this->on();
        $this->http->on('api.anthropic.com', self::answer('"A friendly welcome thread for the garden club: introduce yourself, share what you grow and find your way around."'));

        $result = $this->json($this->api('POST', '/ai/preview', ['id' => 1]));
        $this->assertNull($result['error']);
        $this->assertSame('A friendly welcome thread for the garden club: introduce yourself, share what you grow and find your way around.', $result['suggestion']);

        $call = $this->http->to('api.anthropic.com/v1/messages')[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::KEY, $call['headers']['x-api-key']);
        $this->assertSame('2026-10-21', $call['headers']['anthropic-version']);
        $body = json_decode((string) $call['body'], true);
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame(200, $body['max_tokens']);
        $this->assertSame(['type' => 'disabled'], $body['thinking']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertStringContainsString('<title>Welcome to the garden club</title>', $body['messages'][0]['content']);
        $this->assertStringContainsString('Hello', $body['messages'][0]['content']);

        // Nothing written to FoF SEO by a preview.
        $this->assertSame('suggested', $this->database()->table('surveyor_ai_descriptions')->value('status'));
        $this->assertSame(1, $this->database()->table('surveyor_api_log')->where('service', 'ai')->value('output_tokens') / 40);
    }

    #[Test]
    public function only_public_discussions_and_within_the_caps(): void
    {
        $this->on();
        $this->setting(Settings::key('ai_daily'), '1');
        $this->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::key('ai_daily'), '1');
        $this->http->on('api.anthropic.com', self::answer('A description.'));

        $this->assertSame('not_public', $this->json($this->api('POST', '/ai/preview', ['id' => 2]))['error']);
        $this->assertNull($this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertSame('daily_cap', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertCount(1, $this->http->to('anthropic'));
    }

    #[Test]
    public function the_monthly_token_budget_stops_it(): void
    {
        $this->on();
        $this->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::key('ai_monthly_tokens'), '10000');
        $this->http->on('api.anthropic.com', self::answer('A description.', 9990, 20));

        $this->json($this->api('POST', '/ai/preview', ['id' => 1]));
        $this->assertSame('monthly_cap', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertCount(1, $this->http->to('anthropic'));

        $usage = $this->json($this->api('GET', '/ai'))['usage'];
        $this->assertSame(10010, $usage['input'] + $usage['output']);
    }

    #[Test]
    public function a_refusal_or_an_error_is_reported_not_saved(): void
    {
        $this->on();
        $this->http->on('api.anthropic.com', [self::answer('', 10, 0, 'refusal'), ['status' => 529, 'body' => ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]]);

        $this->assertSame('refused', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertSame('Overloaded', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertSame('paused', $this->json($this->api('POST', '/ai/preview', ['id' => 1]))['error']);
        $this->assertSame(0, $this->database()->table('surveyor_ai_descriptions')->count());
    }

    #[Test]
    public function applying_writes_fof_seo_and_undo_puts_it_back(): void
    {
        $this->on();
        $this->http->on('api.anthropic.com', self::answer('Say hello to the garden club and meet the members who grow roses, tomatoes and everything in between.'));
        $this->api('POST', '/ai/preview', ['id' => 1]);

        $this->assertNull($this->json($this->api('POST', '/ai/apply', ['id' => 1, 'text' => 'Meet the garden club: <b>roses</b>, tomatoes and more.']))['error']);

        $meta = SeoMeta::query()->where('object_type', 'discussions')->where('object_id', 1)->first();
        $this->assertSame('Meet the garden club: roses, tomatoes and more.', $meta->description);
        $this->assertFalse((bool) $meta->auto_update_data, 'FoF SEO would overwrite it on the next reply otherwise');

        // The page's meta description is the one applied.
        $html = (string) $this->send($this->request('GET', '/d/1-welcome-to-the-garden-club')->withHeader('Accept', 'text/html'))->getBody();
        $this->assertStringContainsString('content="Meet the garden club: roses, tomatoes and more."', $html);

        $this->assertNull($this->json($this->api('POST', '/ai/undo', ['id' => 1]))['error']);
        $meta->refresh();
        $this->assertNotSame('Meet the garden club: roses, tomatoes and more.', $meta->description);
        $this->assertTrue((bool) $meta->auto_update_data);
    }

    #[Test]
    public function a_hand_written_description_is_never_replaced(): void
    {
        $this->on();
        $this->app();
        $meta = SeoMeta::build('discussions', 1, false);
        $meta->description = 'Written by the forum owner.';
        $meta->save();

        $this->database()->table('surveyor_ai_descriptions')->insert(['discussion_id' => 1, 'suggestion' => 'From the AI.', 'status' => 'suggested', 'created_at' => \Carbon\Carbon::now()]);

        $this->assertSame('human_written', $this->json($this->api('POST', '/ai/apply', ['id' => 1]))['error']);
        $this->assertSame('Written by the forum owner.', $meta->refresh()->description);

        $candidates = array_column($this->json($this->api('GET', '/ai/candidates', query: ['all' => 1]))['data'], 'id');
        $this->assertNotContains(1, $candidates);
    }

    #[Test]
    public function descriptions_are_cleaned_and_cut_at_a_word(): void
    {
        $this->assertSame('Plain text here.', Describer::clean("  \"Plain\n text <i>here</i>.\"  "));
        $long = Describer::clean(str_repeat('gardening ', 30));
        $this->assertLessThanOrEqual(Describer::MAX_CHARS, mb_strlen($long));
        $this->assertStringEndsWith('gardening…', $long);
    }
}
