<?php

namespace Ernestdefoe\Surveyor\Rules;

/**
 * Turns a rule into a regular expression and runs it safely. A path is
 * matched as it appears below the forum's folder: '/d/12-hello'.
 */
final class Matcher
{
    public const MAX_LENGTH = 300;

    /** Backtracking budget for one match: a pathological pattern fails instead of hanging. */
    private const BACKTRACK_LIMIT = 100_000;

    /** The compiled expression, or null when the rule cannot be compiled. */
    public static function compile(string $type, string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_LENGTH || ! mb_check_encoding($pattern, 'UTF-8')) {
            return null;
        }

        switch ($type) {
            case 'exact':
                return '~^'.preg_quote(self::trim($pattern), '~').'/?$~iu';
            case 'wildcard':
                $regex = '';
                foreach (mb_str_split($pattern) as $char) {
                    $regex .= match ($char) {
                        '*' => '.*',
                        '?' => '.',
                        default => preg_quote($char, '~'),
                    };
                }

                return '~^'.$regex.'$~iu';
            case 'regex':
                // Nested quantifiers like (a+)+ are how a pattern blows up; refuse them outright.
                if (preg_match('/\((?:[^()\\\\]|\\\\.)*[+*}](?:[^()\\\\]|\\\\.)*\)\s*[+*{]/', $pattern)) {
                    return null;
                }
                $compiled = '~'.str_replace('~', '\~', $pattern).'~u';

                return @preg_match($compiled, '') === false ? null : $compiled;
        }

        return null;
    }

    public static function matches(string $compiled, string $path): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            return @preg_match($compiled, $path) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    private static function trim(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
