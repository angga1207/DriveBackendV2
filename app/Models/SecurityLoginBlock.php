<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLoginBlock extends Model
{
    protected $table = 'security_login_blocks';

    protected $fillable = [
        'ip_address',
        'user_id',
        'blocked_until',
        'reason',
    ];

    protected $casts = [
        'blocked_until' => 'datetime',
    ];
}
