<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        foreach ($databases as $connection) {
            $this->info("Migrating: $connection");

            Artisan::call('migrate', [
                '--database' => $connection,
                '--force' => true,
            ]);

            $this->info(Artisan::output());
        }

        $this->info("✅ Migrations completed for all databases.");
    }
}