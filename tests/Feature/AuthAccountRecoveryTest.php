<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesUserSchema;
use Tests\TestCase;

class AuthAccountRecoveryTest extends TestCase
{
    use CreatesUserSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUserSchema();
    }

    private function account(string $access = 'true'): User
    {
        $user = User::create([
            'fullname' => 'Test Account', 'firstname' => 'Test', 'lastname' => 'Account',
            'username' => 'test.account', 'email' => 'account@example.test',
            'password' => bcrypt('valid-password'), 'photo' => 'storage/images/default.png',
            'access' => $access, 'isAdmin' => 'false', 'apple_id' => 'apple-test',
        ]);
        $user->delete();

        return $user;
    }

    public function test_google_restores_allowed_account_and_revokes_old_tokens(): void
    {
        $user = $this->account();
        $old = $user->createToken('old')->accessToken->id;
        $this->postJson('/api/login/google', ['name' => 'Test Account', 'email' => $user->email])
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.access', true)->assertJsonStructure(['data' => ['token']]);
        $this->assertFalse($user->fresh()->trashed());
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_google_denies_deleted_account_without_access(): void
    {
        $user = $this->account('false');
        $this->postJson('/api/login/google', ['name' => 'Test Account', 'email' => $user->email])
            ->assertJsonPath('code', 'ACCOUNT_DELETED')->assertJsonMissingPath('data.token');
        $this->assertTrue($user->fresh()->trashed());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_local_password_is_verified_before_restoring(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'incorrect'])
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertTrue($user->fresh()->trashed());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'valid-password'])
            ->assertJsonPath('status', 'success')->assertJsonPath('data.user.id', $user->id);
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_local_login_denies_deleted_account_without_access(): void
    {
        $user = $this->account('false');
        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'valid-password'])
            ->assertJsonPath('code', 'ACCOUNT_DELETED');
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_semesta_entry_points_follow_deleted_account_access(): void
    {
        $user = $this->account('false');
        Http::fake(['*' => Http::response(['atribut_user' => [
            'username' => $user->username, 'email' => $user->email, 'fullname' => 'Test Account', 'id' => 123,
        ]])]);
        foreach (['/api/login/semesta', '/api/mobile-login', '/api/auto-login'] as $url) {
            $credentials = ['username' => $user->username, 'password' => 'valid-password'];
            $this->postJson($url, $credentials)->assertJsonPath('code', 'ACCOUNT_DELETED');
            $this->assertTrue($user->fresh()->trashed());
            $user->access = 'true';
            $user->save();
            $this->postJson($url, $credentials)->assertJsonPath('status', 'success')->assertJsonPath('data.user.id', $user->id);
            $user = $user->fresh();
            $this->assertFalse($user->trashed());
            $user->access = 'false';
            $user->save();
            $user->delete();
        }
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_mobile_local_fallback_restores_account(): void
    {
        $user = $this->account();
        Http::fake(['*' => Http::response([], 401)]);
        $this->postJson('/api/mobile-login', ['username' => $user->username, 'password' => 'valid-password'])
            ->assertJsonPath('status', 'success');
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_apple_existing_identity_follows_access_rule(): void
    {
        $user = $this->account('false');
        $this->postJson('/api/login/apple', ['userIdentifier' => 'apple-test'])->assertJsonPath('code', 'ACCOUNT_DELETED');
        $user->access = 'true';
        $user->save();
        $this->postJson('/api/login/apple', ['userIdentifier' => 'apple-test'])->assertJsonPath('status', 'success');
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_new_google_registration_succeeds_without_access(): void
    {
        $this->postJson('/api/login/google', ['name' => 'Test Account', 'email' => 'new@example.test'])
            ->assertJsonPath('status', 'success')->assertJsonPath('data.user.access', false);
        $this->assertSame(1, User::count());
    }

    public function test_validation_and_provider_failures_are_explained(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['username', 'password']]);
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['wrong' => 'payload'])]);
        $this->postJson('/api/login/semesta', ['username' => 'someone', 'password' => 'valid-password'])
            ->assertStatus(503)->assertJsonPath('code', 'PROVIDER_UNAVAILABLE');
        $this->postJson('/api/login/semesta', ['username' => 'someone', 'password' => 'valid-password'])
            ->assertStatus(502)->assertJsonPath('code', 'PROVIDER_INVALID_RESPONSE');
    }

    public function test_failed_semesta_verification_does_not_restore_account(): void
    {
        $user = $this->account();
        Http::fake(['*' => Http::response([], 401)]);
        $this->postJson('/api/login/semesta', ['username' => $user->username, 'password' => 'incorrect'])
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertTrue($user->fresh()->trashed());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_semesta_identity_conflict_is_explained_without_creating_account(): void
    {
        $user = $this->account();
        User::create(['username' => 'other', 'email' => 'other@example.test', 'password' => bcrypt('password')]);
        Http::fake(['*' => Http::response(['atribut_user' => [
            'username' => $user->username, 'email' => 'other@example.test', 'fullname' => 'Test Account',
        ]])]);
        $this->postJson('/api/login/semesta', ['username' => $user->username, 'password' => 'valid-password'])
            ->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_CONFLICT');
        $this->assertTrue($user->fresh()->trashed());
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_active_google_account_without_access_still_reaches_access_approval_flow(): void
    {
        $user = $this->account('false');
        $user->restore();
        $this->postJson('/api/login/google', ['name' => 'Test Account', 'email' => $user->email])
            ->assertJsonPath('status', 'success')->assertJsonPath('data.user.access', false);
    }

    public function test_server_failure_rolls_back_restore_and_returns_reference(): void
    {
        $user = $this->account();
        Schema::drop('activity_log');
        $response = $this->postJson('/api/login/google', ['name' => 'Test Account', 'email' => $user->email]);
        $response->assertStatus(500)->assertJsonPath('code', 'AUTH_SERVER_ERROR')->assertJsonStructure(['reference']);
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertTrue($user->fresh()->trashed());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, DB::transactionLevel());
    }
}
