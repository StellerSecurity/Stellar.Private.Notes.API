<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') return $next($request);
        if (!is_string($key) || strlen($key) > 255) {
            return response()->json(['response_message' => 'Invalid idempotency key'], 422);
        }
        $userId = $request->attributes->get('auth_user_id');
        if (!is_int($userId) || $userId < 1) {
            return response()->json(['response_message' => 'Unauthorized'], 401);
        }
        $scope = hash('sha256', json_encode([$userId, $request->method(), $request->path(), $key], JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode($this->canonical($request->all()), JSON_THROW_ON_ERROR));

        // The unique key serializes concurrent requests. The receipt and note
        // writes commit together, so a failed request cannot leave a success marker.
        return DB::transaction(function () use ($request, $next, $userId, $scope, $fingerprint) {
            DB::table('notes_upload_receipts')->insertOrIgnore([
                'scope_key' => $scope, 'user_id' => $userId, 'request_hash' => $fingerprint,
            ]);
            $query = DB::table('notes_upload_receipts')->where('scope_key', $scope);
            $receipt = $query->lockForUpdate()->first();
            if (!$receipt) throw new \RuntimeException('Upload receipt unavailable');
            if (!hash_equals($receipt->request_hash, $fingerprint)) {
                return response()->json(['response_message' => 'Idempotency key reused for a different request'], 409);
            }
            if ($receipt->response_status !== null) {
                // Replay the original legacy or opt-in acknowledgment, never a generic success.
                return response($receipt->response_body, $receipt->response_status, ['Content-Type' => 'application/json']);
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $query->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                ]);
            } else {
                $query->delete();
            }
            return $response;
        }, 3); // Retry a transient database deadlock without losing the receipt.
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }
}
