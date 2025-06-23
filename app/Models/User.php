<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role_id',
        'status',
        'auth_method'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];


    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // Быстрая проверка роли в коде:
    public function hasRole(string $slug): bool
    {
        return $this->role && $this->role->slug === $slug;
    }


}
