<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class WorkflowValidator
{
    private const TRIGGERS = ['contact.created', 'lead.created', 'deal.stage_changed', 'task.completed'];

    private const ACTIONS = ['assign_user', 'create_task', 'send_email', 'webhook'];

    private const OPERATORS = ['eq', 'neq', 'contains', 'starts_with', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_null', 'not_null'];

    /** @return array<string, mixed> */
    public function validate(array $config): array
    {
        $errors = [];
        $trigger = Arr::get($config, 'trigger');
        $conditions = Arr::get($config, 'conditions', []);
        $actions = Arr::get($config, 'actions');

        if (! is_array($trigger) || ! is_string($trigger['type'] ?? null) || ! in_array($trigger['type'], self::TRIGGERS, true)) {
            $errors['config.trigger.type'][] = 'A valid trigger type is required.';
        }

        if (! is_array($conditions)) {
            $errors['config.conditions'][] = 'Conditions must be an array.';
        } else {
            foreach ($conditions as $index => $condition) {
                if (! is_array($condition) || ! is_string($condition['field'] ?? null) || ! in_array($condition['operator'] ?? null, self::OPERATORS, true)) {
                    $errors["config.conditions.$index"][] = 'Condition must contain a field and a supported operator.';
                }
            }
        }

        if (! is_array($actions) || count($actions) === 0) {
            $errors['config.actions'][] = 'At least one action is required.';
        } else {
            foreach ($actions as $index => $action) {
                if (! is_array($action) || ! in_array($action['type'] ?? null, self::ACTIONS, true)) {
                    $errors["config.actions.$index.type"][] = 'This action type is not supported.';

                    continue;
                }

                if ($action['type'] === 'assign_user' && ! isset($action['user_id'])) {
                    $errors["config.actions.$index.user_id"][] = 'assign_user requires user_id.';
                }
                if ($action['type'] === 'create_task' && ! is_string($action['title'] ?? null)) {
                    $errors["config.actions.$index.title"][] = 'create_task requires a title.';
                }
                if ($action['type'] === 'send_email' && ! is_string($action['to'] ?? null)) {
                    $errors["config.actions.$index.to"][] = 'send_email requires a recipient expression.';
                }
                if ($action['type'] === 'webhook' && ! isset($action['endpoint_id'])) {
                    $errors["config.actions.$index.endpoint_id"][] = 'webhook requires endpoint_id.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'trigger' => ['type' => $trigger['type']],
            'conditions' => array_values($conditions),
            'actions' => array_values($actions),
        ];
    }
}
