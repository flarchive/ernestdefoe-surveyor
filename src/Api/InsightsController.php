<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Guard\Regressions;
use Ernestdefoe\Surveyor\Links\Graph;
use Ernestdefoe\Surveyor\Links\Related;
use Ernestdefoe\Surveyor\Site;
use Flarum\Discussion\Discussion;
use Flarum\User\Guest;
use Psr\Http\Message\ServerRequestInterface;

/** The regression guard and the internal-links analysis: read-only views of Surveyor's own tables. */
class InsightsController extends Controller
{
    protected array $actions = ['regressions', 'links', 'related'];

    public function __construct(
        protected Regressions $regressions,
        protected Graph $graph,
        protected Related $related,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function regressions(ServerRequestInterface $request): array
    {
        return ['data' => array_map(fn ($r) => $r + ['url' => $this->site->url($r['path'])], $this->regressions->all())];
    }

    /** @return array<string, mixed> */
    protected function links(ServerRequestInterface $request): array
    {
        return $this->graph->summary();
    }

    /** Discussions worth linking from (or to) one discussion. @return array<string, mixed> */
    protected function related(ServerRequestInterface $request): array
    {
        $input = trim((string) $this->query($request, 'discussion', ''));
        $path = preg_match('~^https?://~i', $input) ? (string) $this->site->path($input) : $input;
        $id = preg_match('~^(?:/d/)?(\d+)~', $path, $m) ? (int) $m[1] : 0;

        $discussion = $id ? Discussion::whereVisibleTo(new Guest())->whereKey($id)->first() : null;

        if (! $discussion) {
            return ['discussion' => null, 'data' => []];
        }

        return [
            'discussion' => ['id' => (int) $discussion->id, 'title' => $discussion->title],
            'data' => array_map(fn ($r) => $r + ['url' => $this->site->url('/d/'.$r['slug'])], $this->related->find($discussion, 10)),
        ];
    }
}
