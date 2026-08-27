<?php

namespace App\Services;

use App\Models\EntityDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DynamicRecordQuery
{
    public function apply(Builder $query, Request $request, EntityDefinition $definition): Builder
    {
        $fieldTypes = $definition->fields()
            ->where('active', true)
            ->pluck('type', 'name')
            ->all();
        $filters = $request->input('filter', []);
        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filter' => 'Filter must be an object.']);
        }

        $baseColumns = [
            'id' => 'entity_records.id',
            'created_by' => 'entity_records.created_by',
            'updated_by' => 'entity_records.updated_by',
            'created_at' => 'entity_records.created_at',
            'updated_at' => 'entity_records.updated_at',
        ];

        foreach ($filters as $field => $definitionFilter) {
            $field = str_starts_with((string) $field, 'data.')
                ? substr((string) $field, 5)
                : (string) $field;
            if (! is_array($definitionFilter)) {
                throw ValidationException::withMessages(["filter.$field" => 'This filter is not allowed.']);
            }
            $operator = $definitionFilter['operator'] ?? 'eq';
            $value = $definitionFilter['value'] ?? null;

            if (isset($baseColumns[$field])) {
                $this->applyColumnFilter($query, $baseColumns[$field], $operator, $value, $field);

                continue;
            }
            if (! array_key_exists($field, $fieldTypes)) {
                throw ValidationException::withMessages(["filter.$field" => 'This field is not defined for this entity.']);
            }

            $this->applyJsonFilter($query, $field, $fieldTypes[$field], $operator, $value);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $expression = $this->jsonExpression();
            $query->whereRaw(
                $this->driver() === 'pgsql' ? "$expression::text ILIKE ?" : "$expression LIKE ?",
                ['%'.addcslashes($search, '%_').'%'],
            );
        }

        $sort = (string) $request->input('sort', 'created_at');
        $direction = strtolower((string) $request->input('direction', 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => 'Direction must be asc or desc.']);
        }

        if (isset($baseColumns[$sort])) {
            return $query->orderBy($baseColumns[$sort], $direction)->orderBy('entity_records.id', $direction);
        }
        if (! array_key_exists($sort, $fieldTypes)) {
            throw ValidationException::withMessages(['sort' => 'This sort field is not allowed.']);
        }

        return $query->orderByRaw($this->typedExpression($sort, $fieldTypes[$sort]).' '.$direction)
            ->orderBy('entity_records.id', $direction);
    }

    private function applyJsonFilter(Builder $query, string $field, string $type, string $operator, mixed $value): void
    {
        $expression = $this->typedExpression($field, $type);
        $operators = config('tenancy.allowed_filter_operators', []);
        if (! in_array($operator, $operators, true)) {
            throw ValidationException::withMessages(["filter.$field.operator" => 'This operator is not allowed.']);
        }

        match ($operator) {
            'eq' => $query->whereRaw("$expression = ?", [$value]),
            'neq' => $query->whereRaw("$expression <> ?", [$value]),
            'contains' => $query->whereRaw($this->textExpression($field).' LIKE ?', ['%'.addcslashes((string) $value, '%_').'%']),
            'starts_with' => $query->whereRaw($this->textExpression($field).' LIKE ?', [addcslashes((string) $value, '%_').'%']),
            'gt' => $query->whereRaw("$expression > ?", [$value]),
            'gte' => $query->whereRaw("$expression >= ?", [$value]),
            'lt' => $query->whereRaw("$expression < ?", [$value]),
            'lte' => $query->whereRaw("$expression <= ?", [$value]),
            'between' => $this->between($query, $expression, $value, $field),
            'in' => $this->in($query, $expression, $value, $field, false),
            'not_in' => $this->in($query, $expression, $value, $field, true),
            'is_null' => $query->whereRaw("$expression IS NULL"),
            'not_null' => $query->whereRaw("$expression IS NOT NULL"),
            default => throw ValidationException::withMessages(["filter.$field.operator" => 'Unsupported operator.']),
        };
    }

    private function applyColumnFilter(Builder $query, string $column, string $operator, mixed $value, string $field): void
    {
        match ($operator) {
            'eq' => $query->where($column, '=', $value),
            'neq' => $query->where($column, '!=', $value),
            'contains' => $query->where($column, 'like', '%'.addcslashes((string) $value, '%_').'%'),
            'starts_with' => $query->where($column, 'like', addcslashes((string) $value, '%_').'%'),
            'gt' => $query->where($column, '>', $value),
            'gte' => $query->where($column, '>=', $value),
            'lt' => $query->where($column, '<', $value),
            'lte' => $query->where($column, '<=', $value),
            'between' => $this->betweenColumn($query, $column, $value, $field),
            'in' => $this->inColumn($query, $column, $value, $field, false),
            'not_in' => $this->inColumn($query, $column, $value, $field, true),
            'is_null' => $query->whereNull($column),
            'not_null' => $query->whereNotNull($column),
            default => throw ValidationException::withMessages(["filter.$field.operator" => 'Unsupported operator.']),
        };
    }

    private function typedExpression(string $field, string $type): string
    {
        $expression = $this->jsonExpression($field);
        if (in_array($type, ['number', 'decimal', 'currency'], true)) {
            return $this->driver() === 'pgsql'
                ? "NULLIF($expression, '')::numeric"
                : "CAST(NULLIF($expression, '') AS REAL)";
        }

        return $expression;
    }

    private function textExpression(string $field): string
    {
        return $this->driver() === 'pgsql'
            ? $this->jsonExpression($field)
            : "CAST({$this->jsonExpression($field)} AS TEXT)";
    }

    private function jsonExpression(?string $field = null): string
    {
        if ($field === null) {
            return '"entity_records"."data"';
        }
        if (! preg_match('/^[a-z][a-z0-9_]{1,79}$/', $field)) {
            throw ValidationException::withMessages(['filter' => 'The dynamic field name is invalid.']);
        }

        return $this->driver() === 'pgsql'
            ? "\"entity_records\".\"data\"->>'{$field}'"
            : "json_extract(\"entity_records\".\"data\", '$.\"{$field}\"')";
    }

    private function driver(): string
    {
        return (string) config('database.default');
    }

    private function between(Builder $query, string $expression, mixed $value, string $field): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw ValidationException::withMessages(["filter.$field.value" => 'Between requires two values.']);
        }
        $query->whereRaw("$expression BETWEEN ? AND ?", array_values($value));
    }

    private function in(Builder $query, string $expression, mixed $value, string $field, bool $negated): void
    {
        if (! is_array($value) || $value === []) {
            throw ValidationException::withMessages(["filter.$field.value" => 'This operator requires a non-empty array.']);
        }
        $placeholders = implode(',', array_fill(0, count($value), '?'));
        $query->whereRaw("$expression ".($negated ? 'NOT ' : '')."IN ($placeholders)", array_values($value));
    }

    private function betweenColumn(Builder $query, string $column, mixed $value, string $field): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw ValidationException::withMessages(["filter.$field.value" => 'Between requires two values.']);
        }
        $query->whereBetween($column, array_values($value));
    }

    private function inColumn(Builder $query, string $column, mixed $value, string $field, bool $negated): void
    {
        if (! is_array($value) || $value === []) {
            throw ValidationException::withMessages(["filter.$field.value" => 'This operator requires a non-empty array.']);
        }
        $negated ? $query->whereNotIn($column, $value) : $query->whereIn($column, $value);
    }
}
