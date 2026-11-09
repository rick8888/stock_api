<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

abstract class ApiController extends Controller
{
    protected function items($collection): AnonymousResourceCollection
    {
        return EntityResource::collection($collection);
    }

    protected function one(?array $doc, string $message = 'Introuvable'): EntityResource
    {
        abort_unless($doc, 404, $message);

        return new EntityResource($doc);
    }

    protected function empty(): Response
    {
        return response()->noContent();
    }

    protected function ok(string $message = 'OK'): JsonResponse
    {
        return response()->json(['message' => $message]);
    }
}
