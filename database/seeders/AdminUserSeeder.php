<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@lecercleclub.it'],
            [
                'name'       => 'Admin Le Cercle',
                'password'   => bcrypt('LeCercle2026!'),
                'is_admin'   => true,
                'elo_rating' => 1200,
            ]
        );

        $this->command->info('Admin user creato: admin@lecercleclub.it / LeCercle2026!');
    }
}
