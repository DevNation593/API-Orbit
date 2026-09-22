<?php

namespace App\Http\Resources;

use App\Models\PortalInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalInvitationAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PortalInvitation $invitation */
        $invitation = $this->resource;
        $contact = $invitation->relationLoaded('contact')
            ? $invitation->getRelation('contact')
            : null;

        return $invitation->only([
            'id',
            'contact_id',
            'email',
            'status',
            'expires_at',
            'accepted_at',
            'created_at',
            'updated_at',
        ]) + [
            'contact' => $contact?->only([
                'id', 'first_name', 'last_name', 'email',
            ]),
        ];
    }
}
