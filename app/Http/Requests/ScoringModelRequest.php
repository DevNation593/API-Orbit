<?php

namespace App\Http\Requests;

use App\Models\ScoringModel;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ScoringModelRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lead_scoring.manage') === true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('scoring_models', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('model')),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'minimum_score' => ['sometimes', 'integer', 'between:-100000,100000'],
            'maximum_score' => ['sometimes', 'integer', 'between:-100000,100000'],
            'warm_threshold' => ['sometimes', 'integer', 'between:-100000,100000'],
            'hot_threshold' => ['sometimes', 'integer', 'between:-100000,100000'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'max:30'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $existing = $this->isMethod('post') ? null : ScoringModel::query()->find($this->route('model'));
            $minimum = (int) $this->input('minimum_score', $existing?->minimum_score ?? 0);
            $maximum = (int) $this->input('maximum_score', $existing?->maximum_score ?? 100);
            $warm = (int) $this->input('warm_threshold', $existing?->warm_threshold ?? 40);
            $hot = (int) $this->input('hot_threshold', $existing?->hot_threshold ?? 70);
            if (! ($minimum <= $warm && $warm < $hot && $hot <= $maximum)) {
                $validator->errors()->add('hot_threshold', 'Thresholds must satisfy minimum <= warm < hot <= maximum.');
            }
        }];
    }
}
