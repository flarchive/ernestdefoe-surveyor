<?php

namespace Ernestdefoe\Surveyor\Rules;

use Flarum\Foundation\ValidationException;
use Symfony\Contracts\Translation\TranslatorInterface;

class Validator
{
    public function __construct(
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> columns
     */
    public function validate(array $input, ?Rule $existing = null): array
    {
        $get = fn (string $key, mixed $default) => array_key_exists($key, $input) ? $input[$key] : ($existing ? $existing->getAttribute($key) : $default);

        $type = (string) $get('type', 'exact');
        $action = (string) $get('action', 'exclude');
        $scope = (string) $get('scope', 'both');
        $pattern = trim((string) $get('pattern', ''));
        $note = trim((string) $get('note', ''));

        if (! in_array($type, Rule::TYPES, true)) {
            $this->fail('type', 'rule_type_invalid');
        }
        if (! in_array($action, Rule::ACTIONS, true)) {
            $this->fail('action', 'rule_action_invalid');
        }
        if (! in_array($scope, Rule::SCOPES, true)) {
            $this->fail('scope', 'rule_scope_invalid');
        }
        if ($pattern === '') {
            $this->fail('pattern', 'rule_pattern_required');
        }
        if (strlen($pattern) > Matcher::MAX_LENGTH) {
            $this->fail('pattern', 'rule_pattern_long');
        }
        if ($type !== 'regex' && ! str_starts_with($pattern, '/') && ! str_starts_with($pattern, '*')) {
            $this->fail('pattern', 'rule_pattern_path');
        }
        if (Matcher::compile($type, $pattern) === null) {
            $this->fail('pattern', 'rule_regex_invalid');
        }
        if (! mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 255) {
            $this->fail('note', 'rule_note_long');
        }

        return [
            'type' => $type,
            'pattern' => $pattern,
            'action' => $action,
            'scope' => $scope,
            'enabled' => (bool) $get('enabled', true),
            'note' => $note === '' ? null : $note,
        ];
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-surveyor.api.'.$key)]);
    }
}
