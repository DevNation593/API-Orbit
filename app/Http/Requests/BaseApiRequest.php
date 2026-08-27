<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BaseApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function memberRule()
    {
        return Rule::exists('tenant_user', 'user_id')->where(fn ($query) => $query
            ->where('tenant_id', app(TenantContext::class)->requireId())
            ->where('status', 'active'));
    }
}
