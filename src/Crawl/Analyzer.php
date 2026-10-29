<?php

namespace Ernestdefoe\Surveyor\Crawl;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Ernestdefoe\Surveyor\Http\Page;
use Ernestdefoe\Surveyor\Site;

/**
 * Reads the SEO signals from a page's HTML. Only text and numbers come out:
 * nothing from a crawled page is ever shown in the admin as HTML.
 */
class Analyzer
{
    public const MAX_LINKS = 300;

    public function __construct(
        protected Site $site,
    ) {
    }

    /**
     * @return array{title: string|null, title_length: int|null, description: string|null, description_length: int|null,
     *   canonical: string|null, canonical_self: bool|null, noindex: bool, h1_count: int, word_count: int,
     *   links: list<string>, images_no_alt: int, structured_data: string}
     */
    public function analyze(Page $page): array
    {
        $doc = new DOMDocument();
        $html = $page->body === '' ? '<html></html>' : $page->body;

        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $first = function (string $query) use ($xpath): ?\DOMNode {
            $list = $xpath->query($query);

            return $list ? $list->item(0) : null;
        };
        $all = function (string $query) use ($xpath): array {
            $list = $xpath->query($query);

            return $list ? iterator_to_array($list) : [];
        };
        $pageUrl = $page->finalUrl !== '' ? $page->finalUrl : $page->url;

        $title = $this->text($first('//title'));
        $description = null;
        $robots = [];
        foreach ($all('//meta[@name]') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower(trim($meta->getAttribute('name')));
            if ($name === 'description' && $description === null) {
                $description = self::clean($meta->getAttribute('content'));
            } elseif ($name === 'robots' || $name === 'googlebot') {
                $robots[] = strtolower($meta->getAttribute('content'));
            }
        }

        $robots[] = strtolower($page->header('x-robots-tag'));
        $noindex = (bool) preg_match('/\b(noindex|none)\b/', implode(',', $robots));

        $canonical = null;
        $canonicalSelf = null;
        foreach ($all('//link[@rel]') as $link) {
            if ($link instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $canonical = $this->site->resolve($link->getAttribute('href'), $pageUrl);
                $canonicalSelf = $canonical !== null && self::same($canonical, $pageUrl);
                break;
            }
        }

        $base = $pageUrl;
        $baseTag = $first('//base[@href]');
        if ($baseTag instanceof DOMElement) {
            $base = $this->site->resolve($baseTag->getAttribute('href'), $pageUrl) ?? $pageUrl;
        }

        $links = [];
        foreach ($all('//a[@href]') as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $url = $this->site->resolve($a->getAttribute('href'), $base);
            if ($url !== null && $this->site->owns($url)) {
                $links[explode('?', $url, 2)[0]] = true;
                if (count($links) >= self::MAX_LINKS) {
                    break;
                }
            }
        }

        $noAlt = 0;
        foreach ($all('//img') as $img) {
            if ($img instanceof DOMElement && ! $img->hasAttribute('alt')) {
                $noAlt++;
            }
        }

        $structured = 'none';
        foreach ($all('//script[@type]') as $script) {
            if ($script instanceof DOMElement && strtolower(trim($script->getAttribute('type'))) === 'application/ld+json') {
                $decoded = json_decode(trim($script->textContent), true);
                if (! is_array($decoded)) {
                    $structured = 'invalid';
                    break;
                }
                $structured = 'valid';
            }
        }

        // The content a search engine reads: Flarum's server-rendered content, else <main>, else the body.
        $main = $first('//*[@id="flarum-content"]') ?? $first('//main') ?? $first('//body');

        return [
            'title' => $title,
            'title_length' => $title === null ? null : mb_strlen($title),
            'description' => $description,
            'description_length' => $description === null ? null : mb_strlen($description),
            'canonical' => $canonical,
            'canonical_self' => $canonicalSelf,
            'noindex' => $noindex,
            'h1_count' => count($all('//h1')),
            'word_count' => $main ? $this->words($main) : 0,
            'links' => array_keys($links),
            'images_no_alt' => $noAlt,
            'structured_data' => $structured,
        ];
    }

    private function text(?\DOMNode $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $text = self::clean($node->textContent);

        return $text === '' ? null : $text;
    }

    private function words(\DOMNode $node): int
    {
        $text = '';
        $walk = function (\DOMNode $n) use (&$walk, &$text) {
            foreach ($n->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['script', 'style', 'template'], true)) {
                    continue;
                }
                if ($child->nodeType === XML_TEXT_NODE) {
                    $text .= ' '.$child->textContent;
                } else {
                    $walk($child);
                }
            }
        };
        $walk($node);

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text, $m);

        return count($m[0]);
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Same address, ignoring a trailing slash. */
    public static function same(string $a, string $b): bool
    {
        $norm = fn (string $u) => rtrim(explode('#', $u, 2)[0], '/');

        return $norm($a) === $norm($b);
    }
}
