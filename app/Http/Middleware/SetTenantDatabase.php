<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SetTenantDatabase
{
    public function handle(Request $request, Closure $next)
    {
        $clientDomain = $request->header('X-Client-Domain');

        if ($clientDomain === 'https://jibouli.lvmanager.net') {
            $database = env('DB_DATABASE');
        } elseif ($clientDomain === 'https://edu.jibouli.lvmanager.net' || $clientDomain === 'https://edu-jibouli.lvmanager.net') {
            $database = env('EDU_DB_DATABASE');
        } else {
            return response()->json(['error' => 'Unauthorized domain'], 403);
        }

        // Set DB connection dynamically before any queries
        Config::set('database.connections.mysql.database', $database);

        // Reconnect with new DB settings
        DB::purge('mysql');
        DB::reconnect('mysql');

        return $next($request);
    }
}