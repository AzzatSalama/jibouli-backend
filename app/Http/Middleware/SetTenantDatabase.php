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
        $origin = $request->headers->get('origin');

        if ($origin === 'https://ibouli.lvmanager.net') {
            $database = env('DB_DATABASE');
        } elseif ($origin === 'https://edu.jibouli.lvmanager.net' || $origin === 'https://edu-jibouli.lvmanager.net') {
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