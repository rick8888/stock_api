<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'legal_name',
        'ice',
        'city',
        'email',
        'phone',
        'status',
        'users_count',
        'warehouses_count',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
