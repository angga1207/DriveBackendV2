<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_admin_permission_accepts_supported_database_values(): void
    {
        foreach ([true, 'true', 1, '1', false, 'false', 0, '0', null, '', 'yes'] as $value) {
            $user = new User(['isAdmin' => $value]);
            $this->assertSame(in_array($value, [true, 'true', 1, '1'], true), $user->hasAdminAccess());
        }
    }

    public function test_middleware_uses_permission_instead_of_id(): void
    {
        Route::get('/test-admin-permission', fn () => response()->json(['ok' => true]))
            ->middleware(AdminMiddleware::class);
        foreach ([1, 4, 99] as $id) {
            foreach (['true', 'false'] as $flag) {
                $user = new User(['isAdmin' => $flag]);
                $user->id = $id;
                $this->actingAs($user)->getJson('/test-admin-permission')->assertStatus($flag === 'true' ? 200 : 403);
            }
        }
    }

    public function test_former_admin_ids_cannot_access_admin_api_without_permission(): void
    {
        foreach ([1, 4] as $id) {
            $user = new User(['isAdmin' => 'false']);
            $user->id = $id;
            foreach (['/api/v2/admin/analytics', '/api/v2/admin/activities', '/api/v2/getUsers', '/api/v2/security/get-blocked-login-security'] as $url) {
                $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();
            }
        }
    }
}
