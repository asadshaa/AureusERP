<?php

namespace Webkul\Accounting\Services\Import;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Webkul\Accounting\Models\BusinessRule;

final class ConditionalRuleEngine
{
    private const OPERATORS = ['equals', 'not_equals', 'contains', 'starts_with', 'ends_with', 'greater_than', 'less_than', 'blank', 'not_blank', 'in'];

    private const ACTIONS = ['set', 'copy', 'default', 'map'];

    /**
     * Action types that are recognized but intentionally not applied as a value
     * transformation here — e.g. `mark_non_critical`, which ImportPreviewService
     * interprets separately (via matches()) to decide whether a validation failure on the
     * targeted field may be downgraded under the Warn-and-Continue failure policy.
     */
    private const NON_TRANSFORMATION_ACTIONS = ['mark_non_critical'];

    /**
     * @param  array<string, mixed>  $values
     * @param  Collection<int, BusinessRule>  $rules
     * @return array{values: array<string, mixed>, applied_rule_ids: array<int, int>}
     */
    public function apply(array $values, Collection $rules): array
    {
        $applied = [];
        foreach ($rules as $rule) {
            if (! $this->matchesAll($values, (array) $rule->conditions)) {
                continue;
            }

            foreach ((array) $rule->actions as $action) {
                $values = $this->applyAction($values, $action);
            }

            $applied[] = $rule->id;
            if ($rule->stop_processing) {
                break;
            }
        }

        return ['values' => $values, 'applied_rule_ids' => $applied];
    }

    /**
     * Public entry point for callers that only need to know whether a rule's conditions
     * match a row (e.g. deciding whether a rule's non-transformation actions, such as
     * flagging a field non-critical for the Warn-and-Continue failure policy, apply)
     * without going through apply()'s transformation-action whitelist.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, array<string, mixed>>  $conditions
     */
    public function matches(array $values, array $conditions): bool
    {
        return $this->matchesAll($values, $conditions);
    }

    /** @param array<string, mixed> $values @param array<int, array<string, mixed>> $conditions */
    private function matchesAll(array $values, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $operator = (string) ($condition['operator'] ?? '');
            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException("Unsupported rule operator [{$operator}].");
            }

            $actual = $values[(string) ($condition['field'] ?? '')] ?? null;
            $expected = $condition['value'] ?? null;
            $matched = match ($operator) {
                'equals'       => (string) $actual === (string) $expected,
                'not_equals'   => (string) $actual !== (string) $expected,
                'contains'     => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
                'starts_with'  => str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
                'ends_with'    => str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
                'greater_than' => (float) $actual > (float) $expected,
                'less_than'    => (float) $actual < (float) $expected,
                'blank'        => $actual === null || $actual === '',
                'not_blank'    => $actual !== null && $actual !== '',
                'in'           => in_array((string) $actual, array_map('strval', (array) $expected), true),
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $action @return array<string, mixed> */
    private function applyAction(array $values, array $action): array
    {
        $type = (string) ($action['type'] ?? '');
        if (in_array($type, self::NON_TRANSFORMATION_ACTIONS, true)) {
            return $values;
        }
        if (! in_array($type, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unsupported rule action [{$type}].");
        }

        $field = (string) ($action['field'] ?? '');
        $values[$field] = match ($type) {
            'set'     => $action['value'] ?? null,
            'copy'    => $values[(string) ($action['source_field'] ?? '')] ?? null,
            'default' => ($values[$field] ?? null) === null || ($values[$field] ?? '') === '' ? ($action['value'] ?? null) : $values[$field],
            'map'     => ((array) ($action['values'] ?? []))[(string) ($values[$field] ?? '')] ?? ($action['default'] ?? ($values[$field] ?? null)),
        };

        return $values;
    }
}
