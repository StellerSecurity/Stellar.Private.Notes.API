<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        // For POST /notes/upload only (but harmless elsewhere)
        $key = $request->header('Idempotency-Key');
        if (!$key) return $next($request);

        $userId = (int) $request->attributes->get('auth_user_id', 0);

        $exists = DB::table('idempotency_keys')->where('key', $key)->first();
        if ($exists) {
            // Already processed — return 200 OK to make client retry safe
            return response()->json(['ok' => true, 'idempotent' => true]);
        }

        $resp = $next($request);

        // Store key after successful processing
        if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
            DB::table('idempotency_keys')->insert([
                'user_id' => $userId,
                'key'     => $key,
                'created_at' => now(),
            ]);
        }

        return $resp;
    }
}
