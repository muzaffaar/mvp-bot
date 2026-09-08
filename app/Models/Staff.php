<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Staff extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use HasRoles;

    protected $table = 'staff';

    protected $guard_name = 'web';

    protected $fillable = [
        'full_name',
        'name',
        'username',
        'login',
        'password',
        'telegram_chat_id',
        'token',
        'status',
        'lavozim',
        'activation_password',
        'group_name',
        'group_chat_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // public function groups()
    // {
    //     return $this->belongsToMany(
    //         Group::class,
    //         'group_staff'
    //     )
    //     ->withPivot([
    //         'role_id',
    //         'status',
    //         'joined_at',
    //     ])
    //     ->withTimestamps();
    // }

    public function aliases()
    {
        return $this->hasMany(StaffAlias::class);
    }

    public function qrLoginSessions()
    {
        return $this->hasMany(QrLoginSession::class);
    }

    public function securityLogs()
    {
        return $this->hasMany(SecurityLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function authoredTasks()
    {
        return $this->hasMany(Task::class, 'author_id');
    }
}
