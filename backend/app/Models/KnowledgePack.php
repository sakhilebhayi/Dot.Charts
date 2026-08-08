<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgePack extends Model
{
    public $timestamps = false; // only created_at, set explicitly at create time

    protected $fillable = [
        'pack_id',
        'payload_type',
        'strategy_class',
        'account_count',
        'pack_version',
        'title',
        'summary',
        'period',
        'envelope',
        'created_at',
    ];

    protected $casts = [
        'envelope' => 'array',
        'created_at' => 'datetime',
    ];
}
