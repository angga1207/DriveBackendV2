<?php

namespace App\Services;

use App\Models\SecurityLoginAttempt;
use App\Models\SecurityLoginBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SecurityLoginService
{
    private const FAILS_TO_BLOCK = 10;

    public static function getClientIp(): string
    {
        return (string) request()->ip();
    }

    public static function isBlocked(string $ipAddress, ?int $userId): bool
    {
        $now = Carbon::now();

        $query = SecurityLoginBlock::query()
            ->where(function ($q) use ($ipAddress, $userId) {
                $q->where('ip_address', $ipAddress);

                if (!is_null($userId)) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->where(function ($q) use ($now) {
                // blocked_until IS NULL => permanent
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '>', $now);
            });

        return $query->exists();
    }

    public static function recordFailedAttempt(string $ipAddress, ?int $userId, ?string $username): void
    {
        SecurityLoginAttempt::create([
            'ip_address' => $ipAddress,
            'user_id' => $userId,
            'username' => $username,
            'user_agent' => request()->header('user-agent'),
        ]);
    }

    public static function evaluateAndBlockIfNeeded(string $ipAddress, ?int $userId): array
    {
        $failCountByIp = SecurityLoginAttempt::query()
            ->where('ip_address', $ipAddress)
            ->count();

        $failCountByUser = is_null($userId)
            ? 0
            : SecurityLoginAttempt::query()
                ->where('user_id', $userId)
                ->count();

        $shouldBlockIp = $failCountByIp >= self::FAILS_TO_BLOCK;
        $shouldBlockUser = !is_null($userId) && $failCountByUser >= self::FAILS_TO_BLOCK;

        if (!$shouldBlockIp && !$shouldBlockUser) {
            return [
                'blocked' => false,
                'fail_count_by_ip' => $failCountByIp,
                'fail_count_by_user' => $failCountByUser,
            ];
        }

        DB::beginTransaction();
        try {
            $now = Carbon::now();

            if ($shouldBlockIp) {
                SecurityLoginBlock::updateOrCreate(
                    ['ip_address' => $ipAddress, 'user_id' => null],
                    ['blocked_until' => null, 'reason' => 'Too many failed login attempts (IP)']
                );
            }

            if ($shouldBlockUser) {
                SecurityLoginBlock::updateOrCreate(
                    ['user_id' => $userId, 'ip_address' => null],
                    ['blocked_until' => null, 'reason' => 'Too many failed login attempts (User)']
                );
            }

            DB::commit();

            return [
                'blocked' => true,
                'blocked_ip' => $shouldBlockIp,
                'blocked_user' => $shouldBlockUser,
                'fail_count_by_ip' => $failCountByIp,
                'fail_count_by_user' => $failCountByUser,
                'blocked_at' => $now->toISOString(),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            // If blocking fails, do not block the login flow because of that.
            return [
                'blocked' => false,
                'fail_count_by_ip' => $failCountByIp,
                'fail_count_by_user' => $failCountByUser,
            ];
        }
    }

    public static function resolveUserIdByUsername(?string $username): ?int
    {
        if (!$username) return null;

        $user = User::where('username', $username)->first();
        return $user?->id ?? null;
    }
}
