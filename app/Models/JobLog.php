<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobLog extends Model
{
    protected $fillable = [
        'job_class',
        'job_id',
        'level',
        'message',
        'context',
        'extra'
    ];

    protected $casts = [
        'context' => 'array',
        'extra' => 'array'
    ];
}
