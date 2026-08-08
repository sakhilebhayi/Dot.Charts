<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DkpGateDecision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'direction',
        'decision',
        'reason',
        'matched_keywords',
        'pack_title',
        'pack_summary',
        'decided_at',
    ];

    protected $casts = [
        'matched_keywords' => 'array',
        'decided_at' => 'datetime',
    ];
}
