<?php

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;

class InsertAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param  string|null  $connection
     * @return void
     */
    public function run($database = null)
    {
        Config::set('database.connections.mysql.database', $database ?? env('DB_DATABASE'));

        // Reconnect with new DB settings
        DB::purge('mysql');
        DB::reconnect('mysql');

        User::create([
            'email' => 'salamazzat8@gmail.com',
            'password' => Hash::make('12345678'), // Use a secure password
            'role' => 'admin',
        ]);
    }
}

// php artisan tinker
// >>> (new \Database\Seeders\InsertAdminUserSeeder)->run('your_db_name');