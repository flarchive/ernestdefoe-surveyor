<?php

namespace Ernestdefoe\Surveyor\Api;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Site;

/** Rows as the admin sees them: plain values, never markup. */
final class Present
{
    /** @return array<string, mixed> */
    public static function page(object $row, Site $site, bool $full = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'path' => $row->path,
            'url' => $site->url($row->path),
            'classification' => $row->classification,
            'status' => $row->status_code === null ? null : (int) $row->status_code,
            'score' => $row->score === null ? null : (int) $row->score,
            'issues' => json_decode((string) $row->issues, true) ?: [],
            'title' => $row->title,
            'crawledAt' => $row->crawled_at ? Carbon::parse($row->crawled_at)->toIso8601String() : null,
            'responseMs' => $row->response_ms === null ? null : (int) $row->response_ms,
            'queued' => $row->queued_run !== null,
        ];

        if ($full) {
            $data += [
                'foundVia' => $row->found_via,
                'finalUrl' => $row->final_url,
                'error' => $row->error,
                'bytes' => $row->bytes === null ? null : (int) $row->bytes,
                'titleLength' => $row->title_length === null ? null : (int) $row->title_length,
                'description' => $row->description,
                'descriptionLength' => $row->description_length === null ? null : (int) $row->description_length,
                'canonical' => $row->canonical,
                'canonicalSelf' => $row->canonical_self === null ? null : (bool) $row->canonical_self,
                'noindex' => $row->noindex === null ? null : (bool) $row->noindex,
                'h1Count' => $row->h1_count === null ? null : (int) $row->h1_count,
                'wordCount' => $row->word_count === null ? null : (int) $row->word_count,
                'linksOut' => $row->links_out === null ? null : (int) $row->links_out,
                'imagesNoAlt' => $row->images_no_alt === null ? null : (int) $row->images_no_alt,
                'structuredData' => $row->structured_data,
                'ignored' => (bool) $row->link_ignored,
            ];
        }

        return $data;
    }
}
