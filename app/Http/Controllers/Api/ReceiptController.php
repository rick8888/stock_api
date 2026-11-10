<?php

namespace App\Http\Controllers\Api;

use App\Services\ReceiptService;
use Illuminate\Http\Request;

class ReceiptController extends ApiController
{
    public function __construct(private readonly ReceiptService $receipts) {}

    public function index()
    {
        return $this->items($this->receipts->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->receipts->create($request->all()));
    }

    public function validateReceipt(Request $request, string $id)
    {
        return $this->one($this->receipts->validate(
            $id,
            $request->integer('received'),
            $this->actorId(),
            $request->input('lines'),
        ));
    }
}
