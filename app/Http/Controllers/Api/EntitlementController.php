<?php

namespace App\Http\Controllers\Api;

use App\Services\EntitlementService;
use Illuminate\Http\Request;

class EntitlementController extends ApiController
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function show(Request $request)
    {
        return response()->json(
            $this->entitlements->snapshot($request->input('companyId')),
        );
    }
}
