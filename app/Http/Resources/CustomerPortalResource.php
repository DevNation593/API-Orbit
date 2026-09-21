<?php

namespace App\Http\Resources;

use App\Models\CustomerPortal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPortalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CustomerPortal $portal */
        $portal = $this->resource;

        return $portal->only([
            'id',
            'public_id',
            'title',
            'is_active',
            'settings',
            'created_at',
            'updated_at',
        ]);
    }
}
