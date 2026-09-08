<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Staff;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Staff::updateOrCreate(
            [
                'telegram_chat_id' => config('services.group.admin_telegram_chat_id'),
            ],
            [
                'full_name' => 'Telegram Group Admin',
                'login' => 'admin',
                'username' => 'imuzaffaar',
                'password' => 'ChangeMe123!',
                'status' => 'active',
                'lavozim' => 'System Administrator',
            ]
        );

        $admin->syncRoles([
            Role::SuperAdmin->value,
        ]);
    }
}
