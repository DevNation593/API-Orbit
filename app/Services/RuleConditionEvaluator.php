<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

final class RuleConditionEvaluator
{
    /**
     * @param  iterable<int, mixed>  $conditions
     * @param  array<string, mixed>  $context
     */
    public function matches(iterable $conditions, Model|array $subject, string $matchType = 'all', array $context = []): bool
    {
        $results = [];
        foreach ($conditions as $condition) {
            $field = (string) $this->attribute($condition, 'field', '');
            $operator = (string) $this->attribute($condition, 'operator', 'eq');
            $expected = $this->attribute($condition, 'value');
            $results[] = $this->compare($this->value($subject, $field, $context), $operator, $expected);
        }

        if ($results === []) {
            return true;
        }

        return $matchType === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    private function attribute(mixed $condition, string $key, mixed $default = null): mixed
    {
        if (is_array($condition)) {
            return $condition[$key] ?? $default;
        }

        return $condition instanceof Model ? $condition->getAttribute($key) ?? $default : $default;
    }

    /** @param array<string, mixed> $context */
    private function value(Model|array $subject, string $field, array $context): mixed
    {
        if ($field === '' || preg_match('/^[A-Za-z0-9_.-]{1,190}$/', $field) !== 1) {
            return null;
        }

        if (str_starts_with($field, 'event.')) {
            return Arr::get($context, $field);
        }

        if (str_starts_with($field, 'lead.')) {
            $field = substr($field, 5);
        }

        $data = $subject instanceof Model ? $subject->attributesToArray() : $subject;

        return Arr::get($data, $field);
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq', 'equals' => $this->equivalent($actual, $expected),
            'neq', 'not_equals' => ! $this->equivalent($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'not_contains' => ! $this->contains($actual, $expected),
            'starts_with' => is_string($actual) && str_starts_with(mb_strtolower($actual), mb_strtolower((string) $expected)),
            'ends_with' => is_string($actual) && str_ends_with(mb_strtolower($actual), mb_strtolower((string) $expected)),
            'gt', 'greater_than' => $this->ordered($actual, $expected, '>'),
            'gte' => $this->ordered($actual, $expected, '>='),
            'lt', 'less_than' => $this->ordered($actual, $expected, '<'),
            'lte' => $this->ordered($actual, $expected, '<='),
            'in' => is_array($expected) && collect($expected)->contains(fn ($value) => $this->equivalent($actual, $value)),
            'not_in' => is_array($expected) && ! collect($expected)->contains(fn ($value) => $this->equivalent($actual, $value)),
            'is_null' => $actual === null,
            'not_null' => $actual !== null,
            'is_empty' => blank($actual),
            'is_not_empty' => filled($actual),
            'before' => $this->dateComparison($actual, $expected, '<'),
            'after' => $this->dateComparison($actual, $expected, '>'),
            default => false,
        };
    }

    private function equivalent(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return BigDecimal::of((string) $actual)->isEqualTo((string) $expected);
        }
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOL) === filter_var($expected, FILTER_VALIDATE_BOOL);
        }
        if (is_scalar($actual) && is_scalar($expected)) {
            return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
        }

        return $actual === $expected;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return collect($actual)->contains(fn ($value) => $this->equivalent($value, $expected));
        }

        return is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected));
    }

    private function ordered(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return match ($operator) {
            '>' => BigDecimal::of((string) $actual)->isGreaterThan((string) $expected),
            '>=' => BigDecimal::of((string) $actual)->isGreaterThanOrEqualTo((string) $expected),
            '<' => BigDecimal::of((string) $actual)->isLessThan((string) $expected),
            '<=' => BigDecimal::of((string) $actual)->isLessThanOrEqualTo((string) $expected),
        };
    }

    private function dateComparison(mixed $actual, mixed $expected, string $operator): bool
    {
        try {
            $left = CarbonImmutable::parse((string) $actual);
            $right = CarbonImmutable::parse((string) $expected);

            return $operator === '<' ? $left->isBefore($right) : $left->isAfter($right);
        } catch (Throwable) {
            return false;
        }
    }
}
