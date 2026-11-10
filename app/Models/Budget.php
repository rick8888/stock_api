<?php

namespace App\Models;

class Budget extends RelationalDocumentModel
{
    protected $table = 'budgets';

    protected $fillable = ['id', 'payload', 'name', 'period', 'allocated', 'spent', 'company_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'allocated' => 'float',
            'spent' => 'float',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'period' => 'period',
            'allocated' => 'allocated',
            'spent' => 'spent',
            'companyId' => 'company_id',
        ];
    }
}
