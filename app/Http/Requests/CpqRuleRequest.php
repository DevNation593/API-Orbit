<?php

namespace App\Http\Requests;

use App\Models\ApprovalRule;
use App\Models\BundleRule;
use App\Models\DiscountRule;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CpqRuleRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pricing.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();
        $type = (string) $this->route('ruleType');
        $table = match ($type) {
            'pricing' => 'pricing_rules', 'discount' => 'discount_rules',
            'bundle' => 'bundle_rules', 'approval' => 'approval_rules', default => 'pricing_rules',
        };
        $ruleId = $this->route('rule');
        $common = [
            'name' => [$required, 'string', 'max:160', Rule::unique($table, 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($ruleId)],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'conditions' => ['nullable', 'array', 'max:100'],
            'conditions.*.field' => ['required_with:conditions', 'string', 'regex:/^[A-Za-z0-9_.-]{1,190}$/'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(['eq', 'neq', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_null', 'not_null', 'is_empty', 'is_not_empty'])],
            'conditions.*.value' => ['nullable'],
            'match_type' => ['sometimes', Rule::in(['all', 'any'])],
            'active' => ['sometimes', 'boolean'],
        ];

        return match ($type) {
            'pricing' => [...$common,
                'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
                'target_type' => ['sometimes', Rule::in(['all', 'product', 'category'])],
                'target_id' => ['nullable', 'integer', 'min:1'],
                'action_type' => [$required, Rule::in(['set_price', 'percentage_adjustment', 'fixed_adjustment'])],
                'value' => [$required, 'decimal:0,6'],
                'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            ],
            'discount' => [...$common,
                'discount_id' => ['nullable', 'integer', Rule::exists('discounts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
                'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
                'type' => [$required, Rule::in(['percentage', 'fixed'])],
                'value' => [$required, 'decimal:0,6', 'gt:0'], 'maximum_discount' => ['nullable', 'decimal:0,6', 'gt:0'],
                'cumulative' => ['sometimes', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            ],
            'bundle' => [...$common,
                'bundle_id' => [$required, 'integer', Rule::exists('bundles', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
                'minimum_quantity' => ['nullable', 'decimal:0,6', 'gt:0'], 'maximum_quantity' => ['nullable', 'decimal:0,6', 'gt:0'],
            ],
            'approval' => [...$common,
                'approval_process_id' => [$required, 'integer', Rule::exists('approval_processes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            ],
            default => ['name' => ['prohibited']],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = (string) $this->route('ruleType');
            $rule = $this->existingRule($type);

            if ($type === 'discount') {
                $discountType = $this->effectiveInput('type', $rule?->getAttribute('type'));
                $value = $this->effectiveInput('value', $rule?->getAttribute('value'));
                if ($discountType === 'percentage' && is_numeric($value) && bccomp((string) $value, '100', 6) === 1) {
                    $validator->errors()->add('value', 'A percentage discount cannot exceed 100.');
                }
            }

            if ($type === 'pricing') {
                $targetType = $this->effectiveInput('target_type', $rule?->getAttribute('target_type') ?? 'all');
                $targetId = $this->effectiveInput('target_id', $rule?->getAttribute('target_id'));
                if ($targetType !== 'all' && blank($targetId)) {
                    $validator->errors()->add('target_id', 'A targeted pricing rule requires target_id.');
                } elseif ($targetType === 'product' && filled($targetId) && ! Product::query()->whereKey($targetId)->exists()) {
                    $validator->errors()->add('target_id', 'The target product does not exist in this tenant.');
                } elseif ($targetType === 'category' && filled($targetId) && ! ProductCategory::query()->whereKey($targetId)->exists()) {
                    $validator->errors()->add('target_id', 'The target category does not exist in this tenant.');
                }

                $actionType = $this->effectiveInput('action_type', $rule?->getAttribute('action_type'));
                $value = $this->effectiveInput('value', $rule?->getAttribute('value'));
                if ($actionType === 'set_price' && is_numeric($value) && bccomp((string) $value, '0', 6) === -1) {
                    $validator->errors()->add('value', 'A fixed price cannot be negative.');
                }
            }

            if ($type === 'bundle') {
                $minimum = $this->effectiveInput('minimum_quantity', $rule?->getAttribute('minimum_quantity'));
                $maximum = $this->effectiveInput('maximum_quantity', $rule?->getAttribute('maximum_quantity'));
                if (is_numeric($minimum) && is_numeric($maximum) && bccomp((string) $maximum, (string) $minimum, 6) === -1) {
                    $validator->errors()->add('maximum_quantity', 'The maximum quantity must be greater than or equal to the minimum.');
                }
            }

            $startsAt = $this->effectiveInput('starts_at', $rule?->getAttribute('starts_at'));
            $endsAt = $this->effectiveInput('ends_at', $rule?->getAttribute('ends_at'));
            if (! $validator->errors()->hasAny(['starts_at', 'ends_at']) && filled($startsAt) && filled($endsAt)
                && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
                $validator->errors()->add('ends_at', 'The rule end must be after its start.');
            }
        }];
    }

    private function existingRule(string $type): ?Model
    {
        $id = $this->route('rule');
        if (! is_numeric($id)) {
            return null;
        }
        $class = match ($type) {
            'pricing' => PricingRule::class,
            'discount' => DiscountRule::class,
            'bundle' => BundleRule::class,
            'approval' => ApprovalRule::class,
            default => null,
        };

        return $class === null ? null : $class::query()->find((int) $id);
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['match_type', 'target_type', 'action_type', 'type'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = strtolower(trim($this->input($field)));
            }
        }
        $this->merge($data);
    }
}
