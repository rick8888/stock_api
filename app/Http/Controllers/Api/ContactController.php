<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $row = ContactMessage::query()->create([
            ...$data,
            'source' => 'landing',
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $row->id,
            'message' => 'Demande reçue. Nous vous recontactons rapidement.',
        ], 201);
    }
}
