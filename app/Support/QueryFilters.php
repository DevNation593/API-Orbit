<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class QueryFilters
{
    /** @param array<string, string> $allowed */
    public function apply(Builder $query, Request $request, array $allowed): Builder
    {
        $filters = $request->input('filter', []);
        $operators = config('tenancy.allowed_filter_operators', []);

        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filter' => 'Filter must be an object.']);
        }

        foreach ($filters as $field => $definition) {
            if (! isset($allowed[$field]) || ! is_array($definition)) {
                throw ValidationException::withMessages(["filter.$field" => 'This filter is not allowed.']);
            }

            $operator = $definition['operator'] ?? 'eq';
            if (! in_array($operator, $operators, true)) {
                throw ValidationException::withMessages(["filter.$field.operator" => 'This operator is not allowed.']);
            }

            $column = $allowed[$field];
            $value = $definition['value'] ?? null;
            $this->applyOperator($query, $column, $operator, $value, $field);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $searchColumns = array_values(array_unique($allowed));
            $query->where(function (Builder $searchQuery) use ($searchColumns, $search): void {
                foreach ($searchColumns as $column) {
                    $searchQuery->orWhereRaw(
                        'CAST('.$column.' AS TEXT) LIKE ?',
                        ['%'.addcslashes($search, '%_').'%'],
                    );
                }
            });
        }

        $sort = (string) $request->input('sort', 'created_at');
        if (! in_array($sort, array_merge(array_keys($allowed), ['created_at']), true)) {
            throw ValidationException::withMessages(['sort' => 'This sort field is not allowed.']);
        }

        $direction = strtolower((string) $request->input('direction', 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => 'Direction must be asc or desc.']);
        }

        return $query->orderBy($allowed[$sort] ?? $sort, $direction)->orderBy('id', $direction);
    }

    private function applyOperator(Builder $query, string $column, string $operator, mixed $value, string $field): void
    {
        match ($operator) {
            'eq' => $query->where($column, '=', $value),
            'neq' => $query->where($column, '!=', $value),
            'contains' => $query->whereRaw('CAST('.$column.' AS TEXT) LIKE ?', ['%'.addcslashes((string) $value, '%_').'%']),
            'starts_with' => $query->whereRaw('CAST('.$column.' AS TEXT) LIKE ?', [addcslashes((string) $value, '%_').'%']),
            'gt' => $query->where($column, '>', $value),
            'gte' => $query->where($column, '>=', $value),
            'lt' => $query->where($column, '<', $value),
            'lte' => $query->where($column, '<=', $value),
            'between' => $this->between($query, $column, $value, $field),
            'in' => $this->in($query, $column, $value, $field, false),
            'not_in' => $this->in($query, $column, $value, $field, true),
            'is_null' => $query->whereNull($column),
            'not_null' => $query->whereNotNull($column),
            default => throw ValidationException::withMessages(["filter.$field.operator" => 'Unsupported operator.']),
        };
    }

    private function between(Builder $query, string $column, mixed $value, string $field): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw ValidationException::withMessages(["filter.$field.value" => 'Between requires two values.']);
        }

        $query->whereBetween($column, array_values($value));
    }

    private function in(Builder $query, string $column, mixed $value, string $field, bool $negated): void
    {
        if (! is_array($value) || count($value) === 0) {
            throw ValidationException::withMessages(["filter.$field.value" => 'This operator requires a non-empty array.']);
        }

        $negated ? $query->whereNotIn($column, $value) : $query->whereIn($column, $value);
    }
}
