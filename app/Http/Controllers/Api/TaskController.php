<?php

namespace App\Http\Controllers\Api;

use App\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index()
    {
        return $this->items($this->tasks->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->tasks->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->tasks->update($id, $request->all()));
    }
}
