<?php

namespace App\Http\Requests;

use App\Models\ApprovalDelegation;
use Illuminate\Validation\Validator;

class ApprovalDelegationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('approvals.manage') === true;
    }

    public function rules(): array
    {
        return [
            'from_user_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', $this->memberRule()],
            'to_user_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', $this->memberRule()],
            'approvable_type' => ['nullable', 'in:quote,discount,deal,contract'],
            'starts_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'active' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $delegation = $this->isMethod('post') ? null : ApprovalDelegation::query()->find($this->route('delegation'));
            $fromUserId = (int) $this->effectiveInput('from_user_id', $delegation?->from_user_id);
            $toUserId = (int) $this->effectiveInput('to_user_id', $delegation?->to_user_id);
            if ($fromUserId > 0 && $fromUserId === $toUserId) {
                $validator->errors()->add('to_user_id', 'An approver cannot delegate to themselves.');
            }
            $startsAt = $this->effectiveInput('starts_at', $delegation?->starts_at);
            $endsAt = $this->effectiveInput('ends_at', $delegation?->ends_at);
            if (! $validator->errors()->hasAny(['starts_at', 'ends_at']) && filled($startsAt) && filled($endsAt)
                && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
                $validator->errors()->add('ends_at', 'The delegation end must be after its start.');
            }
        }];
    }
}
