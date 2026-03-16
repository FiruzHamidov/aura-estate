<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** @use HasFactory<\Database\Factories\RoleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'slug'];

    public static function systemRoles(): array
    {
        return config('roles.system', []);
    }

    public static function upsertSystemRoles(): void
    {
        $now = now();

        $roles = collect(static::systemRoles())
            ->map(fn (array $role) => array_merge($role, [
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->all();

        if ($roles === []) {
            return;
        }

        static::query()->upsert(
            $roles,
            ['slug'],
            ['name', 'description', 'updated_at']
        );
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
