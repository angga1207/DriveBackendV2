<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceSessionService
{
    public function issue(User $user, Request $request): array
    {
        return DB::transaction(function () use ($user, $request) {
            // Serialize issuance for the account, including first login on a new device.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $deviceId = $request->header('X-Device-Id');
            $deviceKey = is_string($deviceId) && preg_match('/^[a-zA-Z0-9_-]{16,128}$/', $deviceId)
                ? hash('sha256', $deviceId) : null;
            if ($deviceKey) {
                $user->tokens()->where('device_key', $deviceKey)->delete();
            }
            // Legacy clients without a stable ID remain separate; User-Agent is not a device ID.
            $ttl = max(1, (int) config('auth_sessions.ttl_minutes', 10080));
            if (config('sanctum.expiration')) $ttl = min($ttl, (int) config('sanctum.expiration'));
            $expiresAt = now()->addMinutes($ttl);
            $token = $user->createToken('authToken', ['*'], $expiresAt);
            $token->accessToken->forceFill([
                'device_key' => $deviceKey,
                'user_agent' => substr($request->header('X-Forwarded-User-Agent') ?: $request->userAgent() ?: '', 0, 2048),
                'ip_address' => $request->ip(),
            ])->save();
            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt->toISOString(), 'session_id' => $token->accessToken->id];
        });
    }

    public function expiresAt(PersonalAccessToken $token): ?CarbonInterface
    {
        $expiresAt = $token->expires_at;
        if (config('sanctum.expiration')) {
            $globalExpiry = $token->created_at->copy()->addMinutes((int) config('sanctum.expiration'));
            if (!$expiresAt || $globalExpiry->lessThan($expiresAt)) $expiresAt = $globalExpiry;
        }
        return $expiresAt;
    }
}
