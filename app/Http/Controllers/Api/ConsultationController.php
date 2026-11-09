<?php

namespace App\Http\Controllers\Api;

use App\Services\ConsultationService;
use Illuminate\Http\Request;

class ConsultationController extends ApiController
{
    public function __construct(private readonly ConsultationService $consultations) {}

    public function index()
    {
        return $this->items($this->consultations->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->consultations->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->consultations->update($id, $request->all()));
    }
}
