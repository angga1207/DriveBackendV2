<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSessionService;
use Illuminate\Http\Request;
use Tests\TestCase;

class DeviceSessionTest extends TestCase
{
    // Skema dasar disimpan di database/migrations/old, jadi kedua path harus dimuat.
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--path' => ['database/migrations/old', 'database/migrations']]);
    }

    private function user(string $username = 'uji.sesi'): User
    {
        return User::forceCreate([
            'name' => $username, 'fullname' => 'Uji Sesi', 'firstname' => 'Uji', 'lastname' => 'Sesi',
            'username' => $username, 'email' => $username.'@example.test',
            'password' => bcrypt('rahasia123'), 'access' => 'true', 'isAdmin' => 'false',
        ]);
    }

    private function issue(User $user, ?string $deviceId, string $agent = 'Chrome/1 Windows'): array
    {
        $request = Request::create('/api/v2/login', 'POST');
        $request->headers->set('User-Agent', $agent);
        if ($deviceId !== null) {
            $request->headers->set('X-Device-Id', $deviceId);
        }

        return app(DeviceSessionService::class)->issue($user, $request);
    }

    public function test_relogin_from_same_device_replaces_the_previous_token(): void
    {
        $user = $this->user();
        $device = str_repeat('a', 32);

        $first = $this->issue($user, $device);
        $second = $this->issue($user, $device);

        $this->assertNotSame($first['session_id'], $second['session_id']);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($second['session_id'], $user->tokens()->first()->id);
    }

    public function test_different_devices_keep_separate_sessions(): void
    {
        $user = $this->user();

        $this->issue($user, str_repeat('a', 32));
        $this->issue($user, str_repeat('b', 32));

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_issued_token_carries_expiry_and_device_metadata(): void
    {
        $user = $this->user();

        $session = $this->issue($user, str_repeat('a', 32), 'Mozilla/5.0 (iPhone) Safari');
        $token = $user->tokens()->findOrFail($session['session_id']);

        $this->assertNotNull($session['expires_at']);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertSame(hash('sha256', str_repeat('a', 32)), $token->device_key);
        $this->assertStringContainsString('iPhone', $token->user_agent);
    }

    public function test_clients_without_a_device_id_are_not_merged_together(): void
    {
        $user = $this->user();

        $this->issue($user, null);
        $this->issue($user, null);

        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(2, $user->tokens()->whereNull('device_key')->count());
    }

    public function test_session_endpoints_list_and_revoke_devices(): void
    {
        $user = $this->user();
        $current = $this->issue($user, str_repeat('a', 32));
        $other = $this->issue($user, str_repeat('b', 32));
        $bearer = ['Authorization' => 'Bearer '.$current['token']];

        $list = $this->getJson('/api/profile/sessions', $bearer)->assertOk()->json('data.data');
        $this->assertCount(2, $list);
        $this->assertTrue(collect($list)->firstWhere('id', $current['session_id'])['is_current']);

        $this->getJson('/api/profile/sessions/current', $bearer)
            ->assertOk()->assertJsonPath('data.id', $current['session_id']);

        $this->deleteJson('/api/profile/sessions/'.$other['session_id'], [], $bearer)
            ->assertOk()->assertJsonPath('data.current_session_revoked', false);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_revoking_the_current_session_is_reported_to_the_client(): void
    {
        $user = $this->user();
        $current = $this->issue($user, str_repeat('a', 32));

        $this->deleteJson('/api/profile/sessions/'.$current['session_id'], [], ['Authorization' => 'Bearer '.$current['token']])
            ->assertOk()->assertJsonPath('data.current_session_revoked', true);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_destroy_others_keeps_only_the_current_session(): void
    {
        $user = $this->user();
        $this->issue($user, str_repeat('b', 32));
        $this->issue($user, str_repeat('c', 32));
        $current = $this->issue($user, str_repeat('a', 32));

        $this->deleteJson('/api/profile/sessions/others', [], ['Authorization' => 'Bearer '.$current['token']])
            ->assertOk()->assertJsonPath('data.revoked_count', 2);
        $this->assertSame([$current['session_id']], $user->tokens()->pluck('id')->all());
    }

    public function test_a_user_cannot_revoke_another_users_session(): void
    {
        $victim = $this->user();
        $attacker = $this->user('penyusup');
        $victimSession = $this->issue($victim, str_repeat('a', 32));
        $attackerSession = $this->issue($attacker, str_repeat('b', 32));

        $this->deleteJson('/api/profile/sessions/'.$victimSession['session_id'], [], ['Authorization' => 'Bearer '.$attackerSession['token']])
            ->assertNotFound();
        $this->assertSame(1, $victim->tokens()->count());
    }

    public function test_expired_tokens_are_rejected_and_hidden_from_the_list(): void
    {
        $user = $this->user();
        $stale = $this->issue($user, str_repeat('b', 32));
        $user->tokens()->whereKey($stale['session_id'])->update(['expires_at' => now()->subMinute()]);
        $current = $this->issue($user, str_repeat('a', 32));

        $this->getJson('/api/profile/sessions', ['Authorization' => 'Bearer '.$stale['token']])->assertUnauthorized();

        $list = $this->getJson('/api/profile/sessions', ['Authorization' => 'Bearer '.$current['token']])->assertOk()->json('data.data');
        $this->assertSame([$current['session_id']], array_column($list, 'id'));
    }
}
