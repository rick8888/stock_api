<?php

namespace App\Http\Controllers\Api;

use App\Services\AnalyticsKpiService;

class AnalyticsController extends ApiController
{
    public function __construct(private readonly AnalyticsKpiService $analytics) {}

    public function snapshot()
    {
        return response()->json($this->analytics->snapshot());
    }
}
