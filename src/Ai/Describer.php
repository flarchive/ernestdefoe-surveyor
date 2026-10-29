<?php

namespace Ernestdefoe\Surveyor\Ai;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use FoF\Seo\SeoMeta\SeoMeta;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Optional AI-written meta descriptions, stored in FoF SEO's own field.
 *
 *  - Preview first: nothing changes until an admin applies a suggestion
 *    (and may edit it before applying).
 *  - A description written by hand in FoF SEO (its "update automatically"
 *    switched off) is never replaced; only FoF SEO's automatic ones, or
 *    one Surveyor applied itself, are.
 *  - Applying switches FoF SEO's automatic updates off for that discussion,
 *    or the next reply would overwrite it. Undo puts back what was there.
 *  - Caps: per day, per minute, and tokens per month; the first post is cut
 *    to 6 000 characters and the answer to 160.
 */
class Describer
{
    public const MAX_CHARS = 160;
    public const INPUT_CHARS = 6000;
    public const PER_MINUTE = 5;
    public const PRICE_IN = 0.10;   // USD per million input tokens, claude-haiku-5-5
    public const PRICE_OUT = 0.50;  // USD per million output tokens

    public function __construct(
        protected Provider $provider,
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected ApiLog $log,
        protected Cache $cache,
    ) {
    }

    public function enabled(): bool
    {
        return in_array($this->settings->get('ai_enabled'), ['1', 'true'], true);
    }

    public function dailyCap(): int
    {
        return $this->settings->int('ai_daily', 1, 1000);
    }

    public function monthlyTokens(): int
    {
        return $this->settings->int('ai_monthly_tokens', 10000, 50_000_000);
    }

    /** @return array{calls: int, input: int, output: int, cost: float} */
    public function usageThisMonth(): array
    {
        $row = $this->db->table('surveyor_api_log')->where('service', 'ai')->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->selectRaw('COUNT(*) as calls, SUM(input_tokens) as input, SUM(output_tokens) as output')->first();

        $in = (int) ($row->input ?? 0);
        $out = (int) ($row->output ?? 0);

        return ['calls' => (int) ($row->calls ?? 0), 'input' => $in, 'output' => $out, 'cost' => round($in / 1e6 * self::PRICE_IN + $out / 1e6 * self::PRICE_OUT, 4)];
    }

    /**
     * Discussions a guest can see whose description could be better: FoF SEO's
     * automatic ones (or none yet), shortest first; hand-written ones are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $offset, bool $all): array
    {
        $grammar = $this->db->getQueryGrammar();
        $length = 'LENGTH(COALESCE('.$grammar->wrap('seo_meta.description').", ''))";

        $rows = Discussion::whereVisibleTo(new Guest())
            ->leftJoin('seo_meta', fn ($j) => $j->on('seo_meta.object_id', '=', 'discussions.id')->where('seo_meta.object_type', '=', 'discussions'))
            ->leftJoin('surveyor_ai_descriptions', 'surveyor_ai_descriptions.discussion_id', '=', 'discussions.id')
            ->where(fn ($q) => $q->whereNull('seo_meta.id')->orWhere('seo_meta.auto_update_data', true)->orWhere('surveyor_ai_descriptions.status', 'applied'))
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->whereRaw("$length < 50")->orWhereNull('seo_meta.id')))
            ->orderByRaw("$length ASC")
            ->orderByDesc('discussions.comment_count')
            ->offset($offset)->limit(20)
            ->toBase()
            ->get(['discussions.id', 'discussions.title', 'seo_meta.description', 'surveyor_ai_descriptions.suggestion', 'surveyor_ai_descriptions.status']);

        return $rows->map(fn (object $r) => [
            'id' => (int) $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'suggestion' => $r->suggestion,
            'status' => $r->status,
        ])->all();
    }

    /** @return array{suggestion: string|null, error: string|null} */
    public function preview(int $discussionId): array
    {
        if (! $this->enabled()) {
            return ['suggestion' => null, 'error' => 'off'];
        }

        $discussion = Discussion::whereVisibleTo(new Guest())->whereKey($discussionId)->first();
        if (! $discussion) {
            return ['suggestion' => null, 'error' => 'not_public'];
        }

        if ($error = $this->capped()) {
            return ['suggestion' => null, 'error' => $error];
        }

        $post = $discussion->firstPost;
        $content = $post instanceof CommentPost ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($post->formatContent()))) : '';
        $content = mb_substr($content, 0, self::INPUT_CHARS);

        $result = $this->provider->describe((string) $discussion->title, $content, $this->settings->locale(), self::MAX_CHARS);
        $this->log->record('ai', 'describe', $result['status'], $result['error'], $result['input'], $result['output']);

        if ($result['error'] !== null || $result['text'] === null) {
            if ($result['status'] !== 200) {
                $this->log->pauseFor('ai', $result['status'] === 529 ? 503 : $result['status']);
            }

            return ['suggestion' => null, 'error' => $result['error'] ?? 'empty'];
        }

        $suggestion = self::clean($result['text']);

        $this->db->table('surveyor_ai_descriptions')->upsert(
            [['discussion_id' => $discussionId, 'suggestion' => $suggestion, 'status' => 'suggested', 'created_at' => Carbon::now()]],
            ['discussion_id'],
            ['suggestion', 'status', 'created_at']
        );

        return ['suggestion' => $suggestion, 'error' => null];
    }

    /** @return string|null an error, or null once applied */
    public function apply(int $discussionId, ?string $text = null): ?string
    {
        $row = $this->db->table('surveyor_ai_descriptions')->where('discussion_id', $discussionId)->first();
        $text = self::clean($text ?? (string) ($row->suggestion ?? ''));

        if ($text === '') {
            return 'empty';
        }

        $discussion = Discussion::query()->find($discussionId);
        if (! $discussion) {
            return 'not_public';
        }

        $meta = SeoMeta::findOneByModel($discussion);

        // Written by hand in FoF SEO: never replaced.
        if ($meta && ! $meta->auto_update_data && ! ($row && $row->status === 'applied' && $meta->description === $row->suggestion)) {
            return 'human_written';
        }

        $previous = $row && $row->status === 'applied'
            ? ['previous' => $row->previous, 'previous_auto' => $row->previous_auto, 'previous_row' => $row->previous_row]
            : ['previous' => $meta?->description, 'previous_auto' => $meta ? (bool) $meta->auto_update_data : null, 'previous_row' => $meta !== null];

        $meta ??= SeoMeta::findByModelOrCreate($discussion);
        $meta->description = $text;
        $meta->auto_update_data = false;
        $meta->save();

        $this->db->table('surveyor_ai_descriptions')->upsert(
            [['discussion_id' => $discussionId, 'suggestion' => $text, 'status' => 'applied', 'created_at' => Carbon::now(), 'applied_at' => Carbon::now()] + $previous],
            ['discussion_id'],
            ['suggestion', 'status', 'applied_at', 'previous', 'previous_auto', 'previous_row']
        );

        return null;
    }

    /** Puts back what FoF SEO had, if the description is still the one applied. */
    public function undo(int $discussionId): ?string
    {
        $row = $this->db->table('surveyor_ai_descriptions')->where('discussion_id', $discussionId)->first();
        $discussion = Discussion::query()->find($discussionId);
        $meta = $discussion ? SeoMeta::findOneByModel($discussion) : null;

        if (! $row || $row->status !== 'applied' || ! $meta) {
            return 'not_applied';
        }

        if ($meta->description !== $row->suggestion) {
            return 'changed_since';
        }

        $meta->description = $row->previous;
        // No row before, or an automatic one: FoF SEO takes over again.
        $meta->auto_update_data = ! $row->previous_row || (bool) $row->previous_auto;
        $meta->save();

        $this->db->table('surveyor_ai_descriptions')->where('discussion_id', $discussionId)->update(['status' => 'undone']);

        return null;
    }

    private function capped(): ?string
    {
        if ($this->log->pausedUntil('ai') !== null) {
            return 'paused';
        }
        if ($this->log->today('ai') >= $this->dailyCap()) {
            return 'daily_cap';
        }
        $usage = $this->usageThisMonth();
        if ($usage['input'] + $usage['output'] >= $this->monthlyTokens()) {
            return 'monthly_cap';
        }

        $minute = 'ernestdefoe-surveyor.ai.'.date('YmdHi');
        $count = (int) $this->cache->get($minute, 0);
        if ($count >= self::PER_MINUTE) {
            return 'minute_cap';
        }
        $this->cache->put($minute, $count + 1, 120);

        return null;
    }

    /** Plain text, one line, at most 160 characters, cut at a word. */
    public static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)), " \t\n\r\0\x0B\"'“”‘’");

        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_CHARS - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > self::MAX_CHARS / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;:-').'…';
    }
}
