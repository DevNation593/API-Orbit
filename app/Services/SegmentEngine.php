<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Models\FieldDefinition;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SegmentEngine
{
    public const TYPES = ['contacts', 'organizations', 'leads', 'deals', 'custom_objects'];

    public const OPERATORS = ['equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'before', 'after', 'is_empty', 'is_not_empty'];

    private const MODELS = [
        'contacts' => Contact::class, 'organizations' => Organization::class,
        'leads' => Lead::class, 'deals' => Deal::class, 'custom_objects' => EntityRecord::class,
    ];

    private const FIELDS = [
        'contacts' => ['first_name', 'last_name', 'email', 'phone', 'status', 'owner_id', 'territory_id'],
        'organizations' => ['name', 'legal_name', 'email', 'phone', 'website', 'industry', 'owner_id', 'territory_id'],
        'leads' => ['first_name', 'last_name', 'email', 'phone', 'status', 'source', 'capture_origin', 'medium', 'campaign', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'converted_at', 'score', 'score_classification', 'owner_id', 'contact_id', 'organization_id', 'territory_id'],
        'deals' => ['name', 'value', 'currency', 'status', 'forecast_category', 'expected_close_date', 'closed_at', 'owner_id', 'contact_id', 'organization_id', 'pipeline_id', 'stage_id', 'sales_team_id', 'branch_id', 'territory_id'],
        'custom_objects' => ['created_by', 'updated_by'],
    ];

    public function normalize(string $type): string
    {
        return match ($type) {
            'contact' => 'contacts', 'companies', 'company', 'organization' => 'organizations',
            'lead' => 'leads', 'opportunities', 'opportunity', 'deal' => 'deals',
            'entity_record' => 'custom_objects', default => $type,
        };
    }

    public function base(string $type, ?int $definitionId = null): Builder
    {
        $type = $this->normalize($type);
        if (! isset(self::MODELS[$type])) {
            $this->invalid('entity_type', 'Unsupported entity type.');
        }
        $query = self::MODELS[$type]::query()->where('tenant_id', app(TenantContext::class)->requireId());
        if ($type === 'custom_objects') {
            if ($definitionId === null || ! EntityDefinition::whereKey($definitionId)->where('active', true)->exists()) {
                $this->invalid('entity_definition_id', 'An active custom object from this tenant is required.');
            }
            $query->where('entity_definition_id', $definitionId);
        } elseif ($definitionId !== null) {
            $this->invalid('entity_definition_id', 'Only custom objects accept a definition id.');
        }

        return $query;
    }

    public function subject(string $type, int $id): Model
    {
        if (! in_array($this->normalize($type), ['contacts', 'leads'], true)) {
            $this->invalid('entity_type', 'Marketing recipients must be contacts or leads.');
        }

        return $this->base($type)->findOrFail($id);
    }

    /** @return array<string, string> */
    public function fields(string $type, ?int $definitionId = null): array
    {
        $type = $this->normalize($type);
        $this->base($type, $definitionId);
        $fields = ['id' => 'number', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
        foreach (self::FIELDS[$type] as $field) {
            $fields[$field] = str_ends_with($field, '_id') || in_array($field, ['value', 'score', 'created_by', 'updated_by'], true)
                ? 'number' : ($field === 'expected_close_date' ? 'date' : (str_ends_with($field, '_at') ? 'datetime' : 'text'));
        }
        $definitions = FieldDefinition::where('active', true)
            ->when($type === 'custom_objects',
                fn ($q) => $q->where('entity_definition_id', $definitionId),
                fn ($q) => $q->where('entity_type', $type)->whereNull('entity_definition_id'))
            ->get(['name', 'type']);
        foreach ($definitions as $definition) {
            if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $definition->name) !== 1) {
                continue;
            }
            $kind = match ($definition->type) {
                'number', 'decimal', 'currency', 'user' => 'number',
                'date' => 'date', 'datetime' => 'datetime', 'boolean' => 'boolean',
                'text', 'textarea', 'email', 'phone', 'url', 'select' => 'text',
                default => null,
            };
            if ($kind !== null) {
                $fields[($type === 'custom_objects' ? 'data.' : 'custom_fields.').$definition->name] = $kind;
            }
        }

        return $fields;
    }

    public function query(string $type, array $definition, ?int $definitionId = null): Builder
    {
        $fields = $this->fields($type, $definitionId);
        $count = 0;
        $this->validateNode($definition, $fields, 0, $count);
        $query = $this->base($type, $definitionId);
        // All OR branches remain inside this group; tenant and soft-delete scopes cannot be bypassed.
        $query->where(fn (Builder $nested) => $this->apply($nested, $definition, $fields));

        return $query;
    }

    private function validateNode(array $node, array $fields, int $depth, int &$count): void
    {
        if ($depth > 5 || ++$count > 100) {
            $this->invalid('definition', 'Filters allow at most 100 nodes and 5 nested levels.');
        }
        $operator = $node['operator'] ?? null;
        if (in_array($operator, ['and', 'or'], true)) {
            if (array_diff(array_keys($node), ['operator', 'conditions']) !== [] ||
                ! is_array($node['conditions'] ?? null) || ! array_is_list($node['conditions']) ||
                count($node['conditions']) < 1 || count($node['conditions']) > 50) {
                $this->invalid('definition', 'Groups require 1 to 50 conditions.');
            }
            foreach ($node['conditions'] as $child) {
                if (! is_array($child)) {
                    $this->invalid('definition', 'Each condition must be an object.');
                }
                $this->validateNode($child, $fields, $depth + 1, $count);
            }

            return;
        }
        $field = $node['field'] ?? null;
        if (array_diff(array_keys($node), ['field', 'operator', 'value']) !== [] ||
            ! is_string($field) || ! isset($fields[$field]) || ! in_array($operator, self::OPERATORS, true)) {
            $this->invalid('definition', 'Unknown field, operator or condition property.');
        }
        if (in_array($operator, ['is_empty', 'is_not_empty'], true)) {
            return;
        }
        $value = $node['value'] ?? null;
        $type = $fields[$field];
        if (! is_scalar($value) || strlen((string) $value) > 2000) {
            $this->invalid('definition', 'Conditions require a scalar value of at most 2000 characters.');
        }
        if ($type === 'number' && (! is_numeric($value) || ! preg_match('/^-?\\d{1,18}(\\.\\d{1,6})?$/', (string) $value))) {
            $this->invalid('definition', 'A finite decimal value is required for numeric fields.');
        }
        if ($type === 'boolean' && ! is_bool($value)) {
            $this->invalid('definition', 'Boolean fields require true or false.');
        }
        if ($type === 'text' && ! is_string($value)) {
            $this->invalid('definition', 'Text fields require a string value.');
        }
        if (($operator === 'contains' && $type !== 'text') ||
            (in_array($operator, ['greater_than', 'less_than'], true) && $type !== 'number') ||
            (in_array($operator, ['before', 'after'], true) && ! in_array($type, ['date', 'datetime'], true))) {
            $this->invalid('definition', 'This operator is not supported for the field type.');
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            try {
                if (! is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}(?:T| |$)/', $value) !== 1) {
                    throw new \InvalidArgumentException;
                }
                CarbonImmutable::parse($value);
                if (Validator::make(['value' => $value], ['value' => ['date']])->fails()) {
                    throw new \InvalidArgumentException;
                }
            } catch (\Throwable) {
                $this->invalid('definition', 'Date filters require an ISO date or timestamp.');
            }
        }
    }

    private function apply(Builder $query, array $node, array $fields): void
    {
        if (in_array($node['operator'], ['and', 'or'], true)) {
            foreach ($node['conditions'] as $child) {
                $method = $node['operator'] === 'or' ? 'orWhere' : 'where';
                $query->{$method}(fn (Builder $nested) => $this->apply($nested, $child, $fields));
            }

            return;
        }
        $field = $node['field'];
        $column = str_replace('.', '->', $field);
        $operator = $node['operator'];
        $kind = $fields[$field];
        if (in_array($operator, ['is_empty', 'is_not_empty'], true)) {
            if ($operator === 'is_empty') {
                $query->whereNull($column);
                if ($kind === 'text') {
                    $query->orWhere($column, '');
                }
            } else {
                $query->whereNotNull($column);
                if ($kind === 'text') {
                    $query->where($column, '<>', '');
                }
            }

            return;
        }
        $value = $node['value'];
        if (in_array($kind, ['date', 'datetime'], true)) {
            $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
            if ($kind === 'date') {
                $value = substr($value, 0, 10);
            }
        }
        $sqlOperator = match ($operator) {
            'equals' => '=', 'not_equals' => '<>', 'contains' => 'LIKE',
            'greater_than', 'after' => '>', 'less_than', 'before' => '<',
        };
        if ($operator === 'contains') {
            $wrapped = $query->getQuery()->getGrammar()->wrap($column);
            $query->whereRaw($wrapped." LIKE ? ESCAPE '!'", ['%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']);
        } elseif (str_contains($field, '.') && $kind === 'datetime') {
            $wrapped = $query->getQuery()->getGrammar()->wrap($column);
            if ($query->getConnection()->getDriverName() === 'pgsql') {
                $query->whereRaw('CAST('.$wrapped.' AS TIMESTAMP WITH TIME ZONE) '.$sqlOperator.' CAST(? AS TIMESTAMP WITH TIME ZONE)', [$value.'+00:00']);
            } else {
                $query->whereRaw('julianday('.$wrapped.') '.$sqlOperator.' julianday(?)', [$value]);
            }
        } elseif (str_contains($field, '.') && $kind === 'number') {
            $wrapped = $query->getQuery()->getGrammar()->wrap($column);
            $query->whereRaw('CAST('.$wrapped.' AS DECIMAL(24,6)) '.$sqlOperator.' ?', [(string) $value]);
        } else {
            $query->where($column, $sqlOperator, $value);
        }
        if ($operator === 'not_equals') {
            $query->orWhereNull($column);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
