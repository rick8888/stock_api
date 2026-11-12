<?php

namespace App\Http\Controllers\Api;

use App\Services\TodayBriefService;
use Illuminate\Http\JsonResponse;

class TodayController extends ApiController
{
    public function __construct(private readonly TodayBriefService $today) {}

    public function brief(): JsonResponse
    {
        return response()->json($this->today->brief());
    }
}
