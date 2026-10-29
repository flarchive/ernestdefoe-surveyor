<?php

namespace Ernestdefoe\Surveyor;

use Flarum\Settings\SettingsRepositoryInterface;

final class Settings
{
    public const PREFIX = 'ernestdefoe-surveyor.';

    /** @var array<string, string> */
    public const DEFAULTS = [
        // daily | weekly | monthly | manual
        'audit_frequency' => 'weekly',
        'max_pages' => '500',
        // Gentle: one page every three seconds.
        'pages_per_minute' => '20',
        'timeout' => '10',
        'keep_runs' => '12',
        'indexnow_enabled' => '0',
        'indexnow_key' => '',
        // Search Console
        'google_property' => '',
        // daily | weekly | off
        'google_refresh' => 'daily',
        'google_refreshed_at' => '',
        'inspect_daily' => '200',
        // PageSpeed Insights
        'speed_daily' => '25',
        // off | weekly
        'speed_schedule' => 'off',
        'speed_pages' => '10',
        'speed_scheduled_at' => '',
        // AI descriptions
        'ai_enabled' => '0',
        'ai_model' => 'claude-haiku-5-5',
        'ai_daily' => '50',
        'ai_monthly_tokens' => '500000',
        // Related discussions under the first post
        'related_enabled' => '0',
        'related_count' => '5',
    ];

    public const FREQUENCIES = ['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'manual' => 0];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public static function key(string $name): string
    {
        return self::PREFIX.$name;
    }

    public function get(string $name): string
    {
        return (string) $this->settings->get(self::key($name), self::DEFAULTS[$name] ?? '');
    }

    public function set(string $name, string $value): void
    {
        $this->settings->set(self::key($name), $value);
    }

    public function int(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->get($name)));
    }

    public function on(string $name): bool
    {
        return in_array($this->get($name), ['1', 'true'], true);
    }

    public function locale(): string
    {
        return (string) $this->settings->get('default_locale', 'en');
    }

    public function maxPages(): int
    {
        return $this->int('max_pages', 10, 20000);
    }

    public function pagesPerMinute(): int
    {
        return $this->int('pages_per_minute', 1, 120);
    }

    public function timeout(): int
    {
        // Kept well under PHP's usual 30 second limit for a web request.
        return $this->int('timeout', 2, 20);
    }

    public function keepRuns(): int
    {
        return $this->int('keep_runs', 2, 100);
    }

    /** Days between scheduled audits; 0 = only when an admin starts one. */
    public function frequencyDays(): int
    {
        return self::FREQUENCIES[$this->get('audit_frequency')] ?? 7;
    }

    public function indexNowKey(): ?string
    {
        $key = $this->get('indexnow_key');

        return preg_match('/^[0-9a-f]{32}$/', $key) ? $key : null;
    }

    /** On, and a key to prove the site is ours. Read from memory: no query. */
    public function indexNowActive(): bool
    {
        return in_array($this->get('indexnow_enabled'), ['1', 'true'], true) && $this->indexNowKey() !== null;
    }
}
