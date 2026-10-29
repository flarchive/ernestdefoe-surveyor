<?php

namespace Ernestdefoe\Surveyor\Crawl;

/**
 * The parts of robots.txt a crawler must obey: the group for "surveyor" if
 * there is one, else the group for "*"; Allow and Disallow with `*` and `$`;
 * the longest matching rule wins, and Allow wins a tie (RFC 9309).
 */
final class Robots
{
    /** @param list<array{0: bool, 1: string}> $rules [allow, pattern] */
    public function __construct(
        public readonly array $rules = [],
        /** @var list<string> */
        public readonly array $sitemaps = [],
    ) {
    }

    public static function parse(string $text): self
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        $sitemaps = [];

        foreach (preg_split('/\R/', substr($text, 0, 500_000)) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                $sitemaps[] = $value;
            } elseif ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inRules = true;
                foreach ($agents as $agent) {
                    $groups[$agent] ??= [];
                    if ($value !== '') {
                        $groups[$agent][] = [$field === 'allow', $value];
                    }
                }
            }
        }

        return new self($groups['surveyor'] ?? $groups['*'] ?? [], $sitemaps);
    }

    /** @param string $path the full path on the host, '/forum/d/1' */
    public function allows(string $path): bool
    {
        $best = -1;
        $allowed = true;

        foreach ($this->rules as [$allow, $pattern]) {
            if (strlen($pattern) >= $best && self::matches($pattern, $path)) {
                if (strlen($pattern) > $best || $allow) {
                    $allowed = $allow;
                }
                $best = strlen($pattern);
            }
        }

        return $allowed;
    }

    private static function matches(string $pattern, string $path): bool
    {
        if (! str_contains($pattern, '*') && ! str_ends_with($pattern, '$')) {
            return str_starts_with($path, $pattern);
        }

        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '~'));

        return preg_match('~^'.$regex.($anchored ? '$' : '').'~', $path) === 1;
    }

    /** @return array{rules: list<array{0: bool, 1: string}>, sitemaps: list<string>} */
    public function toArray(): array
    {
        return ['rules' => $this->rules, 'sitemaps' => $this->sitemaps];
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        return new self(array_values((array) ($data['rules'] ?? [])), array_values((array) ($data['sitemaps'] ?? [])));
    }
}
