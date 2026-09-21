<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BasicAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $username = config('notes_service.username');
        $password = config('notes_service.password');
        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            // A missing server secret must never make this internal API public.
            return response()->json(['response_message' => 'Notes service unavailable'], 503);
        }

        $suppliedUser = $request->getUser();
        $suppliedPassword = $request->getPassword();
        $userMatches = is_string($suppliedUser) && hash_equals($username, $suppliedUser);
        $passwordMatches = is_string($suppliedPassword) && hash_equals($password, $suppliedPassword);
        if (!$userMatches || !$passwordMatches) {
            return response()->json(['response_message' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Basic realm="Notes service"',
                'Cache-Control' => 'no-store',
            ]);
        }

        // Only the authenticated service may supply the end-user identity.
        // The public proxy derives this field from the Stellar ID token.
        $userId = $request->input('user_id');
        if ((!is_int($userId) && !is_string($userId)) ||
            filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return response()->json(['response_message' => 'Invalid user identity'], 422);
        }
        $request->attributes->set('auth_user_id', (int)$userId);
        return $next($request);
    }
}
