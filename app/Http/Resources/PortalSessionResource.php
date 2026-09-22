<?php

namespace App\Http\Resources;

use App\Models\CustomerPortal;
use App\Models\PortalUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CustomerPortal $portal */
        $portal = $this->resource['portal'];
        /** @var PortalUser $user */
        $user = $this->resource['user'];
        /** @var CarbonImmutable $expiresAt */
        $expiresAt = $this->resource['expires_at'];
        $contact = $user->getRelation('contact');
        $profile = (new PortalProfileResource([
            'portal_public_id' => $portal->public_id,
            'portal_title' => $portal->title,
            'email' => $user->email,
            'contact_name' => trim($contact->first_name.' '.$contact->last_name),
        ]))->resolve($request);

        return [
            'access_token' => $this->resource['access_token'],
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'profile' => $profile,
        ];
    }
}
