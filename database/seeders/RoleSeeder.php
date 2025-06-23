<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::create([
            'name' => 'Администратор',
            'slug' => 'admin',
            'description' => 'Полный доступ ко всем функциям'
        ]);

        Role::create([
            'name' => 'Агент',
            'slug' => 'agent',
            'description' => 'Работа с недвижимостью'
        ]);

        Role::create([
            'name' => 'Клиент',
            'slug' => 'client',
            'description' => 'Пользователь платформы'
        ]);
    }
}
