<?php

namespace App\Http\Controllers\Api;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index()
    {
        return $this->items($this->notifications->list());
    }

    public function markRead(string $id)
    {
        $this->notifications->markRead($id);

        return $this->empty();
    }

    public function markAllRead(): JsonResponse
    {
        $count = $this->notifications->markAllRead();

        return response()->json(['marked' => $count]);
    }
}
