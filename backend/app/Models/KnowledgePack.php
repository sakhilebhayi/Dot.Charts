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
        'period_start',
        'period_end',
        'account_count',
        'payload',
        'signature',
        'signing_key_version',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'datetime',
    ];
}
