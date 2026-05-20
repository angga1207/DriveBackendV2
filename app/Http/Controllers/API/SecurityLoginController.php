<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SecurityLoginAttempt;
use App\Models\SecurityLoginBlock;
use App\Models\User;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SecurityLoginController extends Controller
{
    use JsonReturner;

    private function _authorizeAdmin(): bool
    {
        $adminId = auth()->id();
        return $adminId && ($adminId === 1 || $adminId === 4);
    }

    /**
     * Reset global both: attempts + blocks
     */
    public function resetLoginSecurity(Request $request)
    {
        if (!$this->_authorizeAdmin()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|string|max:45',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors(), 400);
        }

        $ipAddress = $request->input('ip_address');
        $userId = $request->input('user_id');

        DB::beginTransaction();
        try {
            $attemptsQuery = SecurityLoginAttempt::query();
            $blocksQuery = SecurityLoginBlock::query();

            if ($ipAddress) {
                $attemptsQuery->where('ip_address', $ipAddress);
                $blocksQuery->where('ip_address', $ipAddress);
            }

            if ($userId) {
                $attemptsQuery->where('user_id', $userId);
                $blocksQuery->where('user_id', $userId);
            }

            // If no filters => reset globally
            if (!$ipAddress && !$userId) {
                SecurityLoginAttempt::query()->delete();
                SecurityLoginBlock::query()->delete();
            } else {
                $attemptsQuery->delete();
                $blocksQuery->delete();
            }

            DB::commit();

            return $this->successResponse(null, 'Log keamanan login berhasil direset', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * List blocked blocks (admin)
     */
    public function getBlockedLoginSecurity(Request $request)
    {
        if (!$this->_authorizeAdmin()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|string|max:45',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors(), 400);
        }

        $ipAddress = $request->query('ip_address');
        $userId = $request->query('user_id');

        $query = SecurityLoginBlock::query();

        if ($ipAddress) $query->where('ip_address', $ipAddress);
        if ($userId) $query->where('user_id', $userId);

        $blocks = $query->orderByDesc('created_at')->get();

        $data = $blocks->map(function (SecurityLoginBlock $block) {
            $user = $block->user_id ? User::find($block->user_id) : null;

            return [
                'id' => $block->id,
                'ip_address' => $block->ip_address,
                'user_id' => $block->user_id,
                'username' => $user?->username,
                'fullname' => $user?->fullname,
                'blocked_until' => $block->blocked_until?->toISOString(),
                'reason' => $block->reason,
                'created_at' => $block->created_at?->toISOString(),
            ];
        });

        return $this->successResponse($data, 'Daftar IP/User yang diblokir', 200);
    }

    /**
     * Restore BLOCKS ONLY (admin)
     * - Remove security_login_blocks rows
     * - Must NOT touch security_login_attempts
     */
    public function restoreLoginSecurity(Request $request)
    {
        if (!$this->_authorizeAdmin()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|string|max:45',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors(), 400);
        }

        $ipAddress = $request->input('ip_address');
        $userId = $request->input('user_id');

        DB::beginTransaction();
        try {
            $blocksQuery = SecurityLoginBlock::query();

            if ($ipAddress) {
                $blocksQuery->where('ip_address', $ipAddress);
            }

            if ($userId) {
                $blocksQuery->where('user_id', $userId);
            }

            // If no filters => restore everything blocks only
            if (!$ipAddress && !$userId) {
                SecurityLoginBlock::query()->delete();
            } else {
                $blocksQuery->delete();
            }

            DB::commit();

            return $this->successResponse(null, 'Login blocks berhasil di-restore', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * List login attempts (admin)
     */
    public function getLoginAttemptsSecurity(Request $request)
    {
        if (!$this->_authorizeAdmin()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|string|max:45',
            'user_id' => 'nullable|integer|exists:users,id',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors(), 400);
        }

        $ipAddress = $request->query('ip_address');
        $userId = $request->query('user_id');
        $limit = (int) ($request->query('limit') ?? 50);

        $query = SecurityLoginAttempt::query();

        if ($ipAddress) $query->where('ip_address', $ipAddress);
        if ($userId) $query->where('user_id', $userId);

        $attempts = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $data = $attempts->map(function (SecurityLoginAttempt $attempt) {
            $user = $attempt->user_id ? User::find($attempt->user_id) : null;

            return [
                'id' => $attempt->id,
                'ip_address' => $attempt->ip_address,
                'user_id' => $attempt->user_id,
                'username' => $attempt->username ?? $user?->username,
                'fullname' => $user?->fullname,
                'user_agent' => $attempt->user_agent,
                'created_at' => $attempt->created_at?->toISOString(),
            ];
        });

        return $this->successResponse($data, 'Daftar SL Attempts', 200);
    }

    /**
     * Restore ATTEMPTS ONLY (admin)
     * - Remove security_login_attempts rows
     * - Must NOT touch security_login_blocks
     */
    public function restoreLoginAttemptsSecurity(Request $request)
    {
        if (!$this->_authorizeAdmin()) {
            return $this->unauthorizedResponse('Akses ditolak', 403);
        }

        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|string|max:45',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors(), 400);
        }

        $ipAddress = $request->input('ip_address');
        $userId = $request->input('user_id');

        DB::beginTransaction();
        try {
            $attemptsQuery = SecurityLoginAttempt::query();

            if ($ipAddress) {
                $attemptsQuery->where('ip_address', $ipAddress);
            }

            if ($userId) {
                $attemptsQuery->where('user_id', $userId);
            }

            // If no filters => restore attempts globally
            if (!$ipAddress && !$userId) {
                SecurityLoginAttempt::query()->delete();
            } else {
                $attemptsQuery->delete();
            }

            DB::commit();

            return $this->successResponse(null, 'Login attempts berhasil di-restore', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
