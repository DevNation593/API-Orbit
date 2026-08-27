<?php

namespace App\Services;

use App\Models\EntityDefinition;
use App\Models\FieldDefinition;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomFieldService
{
    /** @return array<string, mixed> */
    public function validateAndNormalise(string|EntityDefinition $entity, array $values): array
    {
        $definitionsQuery = FieldDefinition::query()->where('active', true);
        if ($entity instanceof EntityDefinition) {
            $definitionsQuery->where('entity_definition_id', $entity->id);
        } else {
            $definitionsQuery->where('entity_type', $entity);
        }

        $definitions = $definitionsQuery
            ->orderBy('position')
            ->get()
            ->keyBy('name');

        $errors = [];
        $normalised = $values;

        foreach ($values as $name => $_value) {
            if (! $definitions->has($name)) {
                $errors["custom_fields.$name"][] = 'This custom field is not defined for this entity.';
            }
        }

        foreach ($definitions as $name => $definition) {
            if (! array_key_exists($name, $normalised)) {
                if ($definition->default_value !== null) {
                    $normalised[$name] = $definition->default_value;
                } elseif ($definition->required) {
                    $errors["custom_fields.$name"][] = 'This field is required.';

                    continue;
                } else {
                    continue;
                }
            }

            $value = $normalised[$name];
            if ($value === null && ! $definition->required) {
                continue;
            }

            $rules = $this->rulesFor($definition);
            $validator = Validator::make([$name => $value], [$name => $rules]);
            if ($validator->fails()) {
                $errors["custom_fields.$name"] = $validator->errors()->get($name);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalised;
    }

    private function rulesFor(FieldDefinition $definition): array
    {
        $options = array_values(array_map('strval', $definition->options ?? []));
        $typeRules = match ($definition->type) {
            'text' => ['string', 'max:5000'],
            'textarea' => ['string', 'max:50000'],
            'number' => ['integer'],
            'decimal', 'currency' => ['numeric'],
            'email' => ['email:rfc', 'max:190'],
            'phone' => ['string', 'max:50'],
            'url' => ['url', 'max:2000'],
            'date' => ['date'],
            'datetime' => ['date'],
            'boolean' => ['boolean'],
            'select' => ['string', Rule::in($options)],
            'multi_select' => ['array', 'max:200', Rule::forEach(fn () => [Rule::in($options)])],
            'user' => ['integer', Rule::exists('tenant_user', 'user_id')->where(fn ($query) => $query
                ->where('tenant_id', app(TenantContext::class)->requireId())
                ->where('status', 'active'))],
            'relation' => ['string', 'max:190'],
            'file' => ['integer', Rule::exists('file_records', 'id')->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->requireId()))],
            default => throw ValidationException::withMessages([
                "custom_fields.{$definition->name}" => 'This custom field type is not supported.',
            ]),
        };

        if ($definition->required) {
            array_unshift($typeRules, 'required');
        }

        foreach (['min', 'max', 'regex'] as $rule) {
            if (array_key_exists($rule, $definition->validation_rules ?? [])) {
                $typeRules[] = $rule.':'.$definition->validation_rules[$rule];
            }
        }

        return $typeRules;
    }
}
