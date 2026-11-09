<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'password' => '',
            'role' => $this->role,
            'title' => $this->title,
            'avatarHue' => $this->avatar_hue,
            'warehouseIds' => $this->warehouse_ids ?? [],
            'productIds' => $this->product_ids ?? [],
            'regionIds' => $this->region_ids ?? [],
            'driverId' => $this->driver_id,
            'companyId' => $this->company_id,
        ];
    }
}
