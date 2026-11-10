<?php

namespace App\Models;

class Category extends RelationalDocumentModel
{
    protected $table = 'categories';

    protected $fillable = ['id', 'payload', 'name', 'company_id'];

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'companyId' => 'company_id',
        ];
    }
}
