<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DeviceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function current(Request $request, DeviceSessionService $sessions): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof \Laravel\Sanctum\PersonalAccessToken, 401);
        return response()->json(['status' => 'success', 'data' => ['id' => $token->id, 'expires_at' => $sessions->expiresAt($token)?->toISOString()]]);
    }

    public function index(Request $request, DeviceSessionService $sessions): JsonResponse
    {
        $tokens = $request->user()->tokens()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when(config('sanctum.expiration'), fn ($query) => $query->where('created_at', '>', now()->subMinutes((int) config('sanctum.expiration'))))
            ->orderByDesc('last_used_at')->orderByDesc('id')->paginate(20);
        $currentId = $request->user()->currentAccessToken()?->id;
        $tokens->through(fn ($token) => [
            'id' => $token->id, 'is_current' => $token->id === $currentId,
            'user_agent' => $token->user_agent, 'ip_address' => $token->ip_address,
            'created_at' => $token->created_at?->toISOString(), 'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $sessions->expiresAt($token)?->toISOString(),
        ]);
        return response()->json(['status' => 'success', 'data' => $tokens]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($id);
        $isCurrent = $request->user()->currentAccessToken()?->id === $token->id;
        $token->delete();
        return response()->json(['status' => 'success', 'message' => 'Sesi berhasil diakhiri.', 'data' => ['current_session_revoked' => $isCurrent]]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        abort_unless($current instanceof \Laravel\Sanctum\PersonalAccessToken, 401);
        $count = $request->user()->tokens()->where('id', '!=', $current->id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Sesi perangkat lain berhasil diakhiri.', 'data' => ['revoked_count' => $count, 'current_session_revoked' => false]]);
    }
}
