<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Находим роль администратора
        $adminRole = Role::where('slug', 'admin')->first();

        // Создаем администратора
        User::updateOrCreate(
            ['phone' => '918555581'],  // уникальный ключ — телефон
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password123'),
                'role_id' => $adminRole->id,
                'status' => 'active',
                'auth_method' => 'password'
            ]
        );

        // При желании можно сразу создать и тестового агента, например:
        $agentRole = Role::where('slug', 'agent')->first();

        User::updateOrCreate(
            ['phone' => '938080888'],
            [
                'name' => 'Agent User',
                'email' => 'agent@example.com',
                'password' => Hash::make('password123'),
                'role_id' => $agentRole->id,
                'status' => 'active',
                'auth_method' => 'password'
            ]
        );

        // И клиента:
        $clientRole = Role::where('slug', 'client')->first();

        User::updateOrCreate(
            ['phone' => '918555583'],
            [
                'name' => 'Client User',
                'email' => 'client@example.com',
                'password' => Hash::make('password123'),
                'role_id' => $clientRole->id,
                'status' => 'active',
                'auth_method' => 'password'
            ]
        );
    }
}
