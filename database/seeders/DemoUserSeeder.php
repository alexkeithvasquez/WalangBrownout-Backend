<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Alex Keith Vasquez', 'email' => 'manager@walangbrownout.ph', 'password' => 'manager123', 'role' => 'manager'],
            ['name' => 'Jenny Santos', 'email' => 'staff@walangbrownout.ph', 'password' => 'staff123', 'role' => 'staff'],
            ['name' => 'Miguel Reyes', 'email' => 'customer@gmail.com', 'password' => 'customer123', 'role' => 'customer'],
        ] as $account) {
            User::updateOrCreate(['email' => $account['email']], $account);
        }
    }
}
