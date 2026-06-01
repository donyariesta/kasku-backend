<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $credentials['username'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $payload = base64_encode(json_encode([
            'id' => $user->id,
            'exp' => time() + (24 * 60 * 60),
        ]));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $token = $payload.'.'.$signature;

        return response()->json($user->load('tenant:id,name'))->cookie(
            'token',
            $token,
            60 * 24,
            '/',
            null,
            app()->environment('production'),
            true,
            false,
            'strict'
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::with('tenant:id,name')->find($request->user()?->id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json($user);
    }

    public function logout(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Logged out'])->withoutCookie('token');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:6|confirmed',
        ]);

        /** @var User|null $user */
        $user = User::find($request->user()?->id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if (!Hash::check($payload['currentPassword'], $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 400);
        }

        $user->password = Hash::make($payload['newPassword']);
        $user->save();

        return response()->json(['success' => true]);
    }
}
