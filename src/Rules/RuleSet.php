<?php

namespace Ernestdefoe\Surveyor\Rules;

/**
 * The enabled rules, read once per process (a crawl tick, a submission)
 * and never on a forum page view.
 */
class RuleSet
{
    /** @var array<string, list<array{action: string, regex: string, id: int}>>|null */
    private ?array $compiled = null;

    public function flush(): void
    {
        $this->compiled = null;
    }

    /** @return 'rule'|null 'rule' when the rules keep this path out of $area (audit | indexnow) */
    public function excludes(string $area, string $path): ?string
    {
        $rules = $this->rules()[$area] ?? [];
        $hasInclude = false;
        $included = false;

        foreach ($rules as $rule) {
            if ($rule['action'] === 'include') {
                $hasInclude = true;
                if (! $included && Matcher::matches($rule['regex'], $path)) {
                    $included = true;
                }
            } elseif (Matcher::matches($rule['regex'], $path)) {
                return 'rule';
            }
        }

        return $hasInclude && ! $included ? 'rule' : null;
    }

    /** @return list<int> ids of the rules that match, for the admin's tester */
    public function matching(string $path): array
    {
        $ids = [];
        foreach ($this->rules() as $rules) {
            foreach ($rules as $rule) {
                if (Matcher::matches($rule['regex'], $path)) {
                    $ids[] = $rule['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, list<array{action: string, regex: string, id: int}>> */
    private function rules(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $compiled = ['audit' => [], 'indexnow' => []];

        foreach (Rule::query()->where('enabled', true)->orderBy('id')->get() as $rule) {
            $regex = Matcher::compile($rule->type, $rule->pattern);
            if ($regex === null) {
                continue;
            }
            foreach ($rule->scope === 'both' ? ['audit', 'indexnow'] : [$rule->scope] as $area) {
                $compiled[$area][] = ['action' => $rule->action, 'regex' => $regex, 'id' => $rule->id];
            }
        }

        return $this->compiled = $compiled;
    }
}
