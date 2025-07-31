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
        $host = $request->getHost(); // e.g., 'edu.jibouli.lvmanager.net'

        if ($host === 'jibouli.lvmanager.net') {
            $database = 'jibouli';
        } elseif ($host === 'edu.jibouli.lvmanager.net') {
            $database = 'jibouli_edu';
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