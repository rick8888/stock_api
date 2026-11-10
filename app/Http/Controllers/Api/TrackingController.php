<?php

namespace App\Http\Controllers\Api;

use App\Services\Logistics\TrackingService;
use Illuminate\Http\Request;

class TrackingController extends ApiController
{
    public function __construct(private readonly TrackingService $tracking) {}

    public function live()
    {
        return $this->items($this->tracking->liveRoutes());
    }

    public function route(string $id)
    {
        return response()->json($this->tracking->routeSnapshot($id));
    }

    public function ping(Request $request)
    {
        $validated = $request->validate([
            'routeId' => ['required', 'string'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracyM' => ['nullable', 'numeric', 'min:0'],
            'speedKmh' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'eventType' => ['nullable', 'string', 'in:ping,departed,arrived,delivered,failed'],
            'source' => ['nullable', 'string', 'in:driver_app,device,manual,system'],
            'recordedAt' => ['nullable', 'date'],
        ]);

        return response()->json($this->tracking->ping($validated));
    }
}
