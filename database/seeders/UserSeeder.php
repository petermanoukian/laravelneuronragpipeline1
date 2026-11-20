<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Web user
        User::create([
            'username' => 'web_admin',
            'email' => 'web@rag.local',
            'password' => Hash::make('password123'),
            'api_token' => null,
        ]);

        // API user
        User::create([
            'username' => 'api_admin',
            'email' => 'api@rag.local',
            'password' => Hash::make('password123'),
            'api_token' => null,
        ]);
    }
}