<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;

class MigrateTenants extends Command
{
    protected $signature = 'migrate:tenants';
    protected $description = 'Run migrations on all tenant databases';

    public function handle()
    {
        $databases = [
            env('DB_DATABASE'),
            env('EDU_DB_DATABASE')
        ];

        foreach ($databases as $database) {
            $this->info("Migrating: $database");

            // Set DB connection dynamically before any queries
            Config::set('database.connections.mysql.database', $database);

            // Reconnect with new DB settings
            DB::purge('mysql');
            DB::reconnect('mysql');

            Artisan::call('migrate', [
                '--database' => 'mysql',
                '--force' => true, // Required for production or non-interactive
            ]);

            $this->info(Artisan::output());
        }

        $this->info("✅ Migrations completed for all databases.");
    }
}