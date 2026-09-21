<?php

namespace App\Http\Resources;

use App\Models\PortalUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalUserAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PortalUser $portalUser */
        $portalUser = $this->resource;
        $contact = $portalUser->relationLoaded('contact')
            ? $portalUser->getRelation('contact')
            : null;

        return $portalUser->only([
            'id',
            'contact_id',
            'email',
            'status',
            'email_verified_at',
            'last_login_at',
            'created_at',
            'updated_at',
        ]) + [
            'contact' => $contact?->only([
                'id', 'first_name', 'last_name', 'email',
            ]),
        ];
    }
}
