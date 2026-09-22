<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class PortalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = Arr::only($this->resource, [
            'portal_public_id',
            'portal_title',
            'email',
            'contact_name',
            'expires_at',
        ]);

        return array_filter(
            $profile,
            fn (mixed $value, string $key): bool => $value !== null || $key === 'expires_at',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
