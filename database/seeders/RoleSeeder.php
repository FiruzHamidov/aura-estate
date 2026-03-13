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

        Role::create([
            'name' => 'Суперадминистратор',
            'slug' => 'superadmin',
            'description' => 'Полный контроль над системой и администраторами'
        ]);

        Role::create([
            'name' => 'Маркетолог',
            'slug' => 'marketing',
            'description' => 'Доступ уровня администратора без доступа к отчетам'
        ]);

        Role::create([
            'name' => 'РОП',
            'slug' => 'rop',
            'description' => 'Руководитель филиала с доступом к данным своего филиала'
        ]);

        Role::create([
            'name' => 'Директор филиала',
            'slug' => 'branch_director',
            'description' => 'Директор филиала с доступом к управлению филиалом'
        ]);
    }
}
