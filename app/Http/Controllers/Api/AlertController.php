<?php

namespace App\Http\Controllers\Api;

use App\Services\AlertService;
use App\Services\PlanningService;
use Illuminate\Http\Request;

class AlertController extends ApiController
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly PlanningService $planning,
    ) {}

    public function index()
    {
        return $this->items($this->alerts->list());
    }

    public function markRead(string $id)
    {
        $this->alerts->markRead($id);

        return $this->empty();
    }

    public function planning(Request $request)
    {
        return $this->items($this->planning->list(
            $request->query('from'),
            $request->query('to'),
        ));
    }
}
