<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownRootKeys;
use App\Models\PortalInvitation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PortalInvitationIndexRequest extends BaseApiRequest
{
    use RejectsUnknownRootKeys;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', PortalInvitation::class);
    }

    public function rules(): array
    {
        return $this->withStrictRootKeys([
            'status' => ['sometimes', Rule::in([
                PortalInvitation::STATUS_PENDING,
                PortalInvitation::STATUS_ACCEPTED,
                PortalInvitation::STATUS_REVOKED,
                PortalInvitation::STATUS_EXPIRED,
            ])],
            'contact_id' => ['sometimes', 'integer', 'min:1'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
            'expires_before' => ['sometimes', 'date'],
            'sort' => ['sometimes', Rule::in(['created_at', 'expires_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ], [
            'status',
            'contact_id',
            'created_from',
            'created_to',
            'expires_before',
            'sort',
            'direction',
            'page',
            'per_page',
        ]);
    }
}
