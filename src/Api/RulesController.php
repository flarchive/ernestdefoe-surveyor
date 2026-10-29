<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Crawl\Eligibility;
use Ernestdefoe\Surveyor\Rules\Rule;
use Ernestdefoe\Surveyor\Rules\RuleSet;
use Ernestdefoe\Surveyor\Rules\Validator;
use Ernestdefoe\Surveyor\Site;
use Psr\Http\Message\ServerRequestInterface;

class RulesController extends Controller
{
    public const MAX_RULES = 200;

    protected array $actions = ['list', 'create', 'update', 'delete', 'test'];

    public function __construct(
        protected Validator $validator,
        protected RuleSet $rules,
        protected Eligibility $eligibility,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function list(ServerRequestInterface $request): array
    {
        return ['data' => Rule::query()->orderBy('id')->limit(self::MAX_RULES)->get()->map(fn (Rule $r) => $r->toArray())->all()];
    }

    /** @return array<string, mixed> */
    protected function create(ServerRequestInterface $request): array
    {
        if (Rule::query()->count() >= self::MAX_RULES) {
            throw new \Flarum\Foundation\ValidationException(['pattern' => resolve('translator')->trans('ernestdefoe-surveyor.api.rule_limit')]);
        }

        $rule = new Rule();
        $rule->forceFill($this->validator->validate($this->body($request)))->save();

        return ['data' => $rule->toArray()];
    }

    /** @return array<string, mixed> */
    protected function update(ServerRequestInterface $request): array
    {
        $rule = Rule::query()->findOrFail($this->id($request));
        $rule->forceFill($this->validator->validate($this->body($request), $rule))->save();

        return ['data' => $rule->toArray()];
    }

    /** @return array<string, mixed> */
    protected function delete(ServerRequestInterface $request): array
    {
        Rule::query()->whereKey($this->id($request))->delete();

        return ['data' => null];
    }

    /** Which rules match an address, and what that means for the audit and IndexNow. @return array<string, mixed> */
    protected function test(ServerRequestInterface $request): array
    {
        $input = trim((string) ($this->body($request)['path'] ?? ''));
        $path = preg_match('~^https?://~i', $input) ? $this->site->path($input) : '/'.ltrim(explode('?', $input, 2)[0], '/');

        if ($path === null) {
            return ['path' => null, 'offsite' => true];
        }

        $this->rules->flush();

        return [
            'path' => $path,
            'rules' => $this->rules->matching($path),
            'audit' => $this->eligibility->reason($path, null, 'audit'),
            'indexnow' => $this->eligibility->reason($path, null, 'indexnow'),
        ];
    }
}
