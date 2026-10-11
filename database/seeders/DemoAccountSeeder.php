<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'second_hand_preview') {
            throw new RuntimeException('Demo accounts are restricted to the local preview database.');
        }

        DB::transaction(function () {
            foreach (config('demo.accounts') as $account) {
                $user = User::firstOrCreate(['email' => $account['email']], [
                    'name' => $account['name'],
                    'mobile_number' => $account['mobile_number'],
                    'password' => config('demo.password'),
                ]);

                if ($user->is_admin || $user->name !== $account['name'] || $user->mobile_number !== $account['mobile_number']) {
                    throw new RuntimeException('Demo email belongs to another account.');
                }

                if (! Hash::check(config('demo.password'), $user->password)) {
                    $user->forceFill(['password' => config('demo.password')])->save();
                }
            }
        });
    }
}
