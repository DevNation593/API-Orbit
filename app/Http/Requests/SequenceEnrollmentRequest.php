<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SequenceEnrollmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sequences.enroll') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'sender_user_id' => ['nullable', 'integer', $this->memberRule()],
            'start_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array', 'max:30'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $targets = (int) filled($this->input('lead_id')) + (int) filled($this->input('contact_id'));
            if ($targets !== 1) {
                $validator->errors()->add('lead_id', 'Exactly one lead_id or contact_id is required.');
            }
        }];
    }
}
