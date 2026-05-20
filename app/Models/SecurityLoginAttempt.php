<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLoginAttempt extends Model
{
    protected $table = 'security_login_attempts';

    protected $fillable = [
        'ip_address',
        'user_id',
        'username',
        'user_agent',
    ];
}
