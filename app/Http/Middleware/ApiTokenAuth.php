<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('token');
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        [$payloadEncoded, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $payloadEncoded, config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $payload = json_decode(base64_decode($payloadEncoded, true) ?: '', true);
        if (!is_array($payload) || empty($payload['id']) || ($payload['exp'] ?? 0) < time()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $user = User::find($payload['id']);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->setUserResolver(static fn () => $user);
        return $next($request);
    }
}
