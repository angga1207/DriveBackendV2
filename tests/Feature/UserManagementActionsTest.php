<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FirebaseNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesUserSchema;
use Tests\TestCase;

class UserManagementActionsTest extends TestCase
{
    use CreatesUserSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUserSchema();
        Notification::fake();
        Schema::table('datas', function (Blueprint $table) {
            $table->string('shared')->default('private');
        });
        foreach (['shared_datas', 'upload_chunk_sessions', 'upload_batches'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                if ($name !== 'upload_batches') {
                    $table->unsignedBigInteger('data_id');
                }
                $table->timestamps();
            });
        }
        $this->admin = $this->account('admin');
        $this->admin->isAdmin = 'true';
        $this->admin->save();
        $this->actingAs($this->admin, 'sanctum');
    }

    private function account(string $name, bool $deleted = false, string $access = 'true'): User
    {
        $user = User::create(['username' => $name, 'email' => $name.'@example.test', 'fullname' => $name, 'access' => $access, 'isAdmin' => 'false']);
        if ($deleted) {
            $user->delete();
        }

        return $user;
    }

    private function action(string $action, array $ids)
    {
        return $this->postJson('/api/v2/admin/users/actions', compact('action', 'ids'));
    }

    public function test_list_filters_deleted_accounts_and_exposes_dates(): void
    {
        $active = $this->account('active');
        $deleted = $this->account('deleted', true);
        $this->getJson('/api/v2/getUsers?state=deleted&order_by=created_at&order_direction=desc')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $deleted->id)
            ->assertJsonStructure(['data' => ['data' => [['created_at', 'deleted_at']]]]);
        $this->getJson('/api/v2/getUsers?state=active&search=active')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $active->id)->assertJsonPath('data.data.0.deleted_at', null);
        $this->getJson('/api/v2/getUsers?order_by=password')->assertStatus(422);
        $this->getJson('/api/v2/getUsers?per_page=1000')->assertStatus(422);
    }

    public function test_access_and_integration_filters_combine_with_search(): void
    {
        $user = $this->account('google-member', false, 'false');
        $user->google_id = 'google-test';
        $user->save();
        $this->account('local-member');
        $this->getJson('/api/v2/getUsers?access=false&integration=google&search=member')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $user->id)
            ->assertJsonPath('data.active_users_count', 3);
        $this->getJson('/api/v2/getUsers?access=true&integration=google')->assertJsonPath('data.total', 0);
        $this->getJson('/api/v2/getUsers?integration=unknown')->assertStatus(422);
    }

    public function test_bulk_delete_and_restore_preserve_access_and_revoke_tokens(): void
    {
        $first = $this->account('first');
        $second = $this->account('second', false, 'false');
        $first->createToken('old');
        $this->action('delete', [$first->id, $second->id])->assertOk()->assertJsonPath('data.affected', 2);
        $this->assertTrue($first->fresh()->trashed());
        $this->assertTrue($second->fresh()->trashed());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->action('restore', [$first->id, $second->id])->assertOk();
        $this->assertFalse($first->fresh()->trashed());
        $this->assertSame('true', $first->fresh()->access);
        $this->assertSame('false', $second->fresh()->access);
    }

    public function test_bulk_access_actions_and_self_protection(): void
    {
        $user = $this->account('member');
        $user->createToken('session');
        $this->action('revoke_access', [$user->id])->assertOk();
        $this->assertSame('false', $user->fresh()->access);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->action('grant_access', [$user->id])->assertOk();
        Notification::assertSentTo($user, FirebaseNotification::class, 2);
        $this->assertSame('true', $user->fresh()->access);
        foreach (['delete', 'force_delete', 'revoke_access'] as $action) {
            $this->action($action, [$user->id, $this->admin->id])->assertStatus(422);
        }
        $this->assertFalse($user->fresh()->trashed());
        $this->assertSame('true', $user->fresh()->access);
    }

    public function test_invalid_or_stale_selection_changes_nothing(): void
    {
        $user = $this->account('deleted', true);
        $active = $this->account('active');
        $this->action('restore', [$user->id, $active->id])->assertStatus(422);
        $this->assertTrue($user->fresh()->trashed());
        $this->action('restore', [$user->id, 999])->assertStatus(422);
        $this->action('restore', [$user->id, $user->id])->assertStatus(422);
        $this->action('delete', [])->assertStatus(422);
        $this->action('delete', range(1, 101))->assertStatus(422);
        $this->action('force_delete', [$active->id])->assertStatus(422);
        $this->assertNotNull($active->fresh());
    }

    public function test_permanent_delete_cleans_owned_records_and_preserves_other_accounts(): void
    {
        $user = $this->account('deleted', true);
        $other = $this->account('other');
        $user->createToken('old');
        $owned = DB::table('datas')->insertGetId(['user_id' => $user->id, 'type' => 'file']);
        $retained = DB::table('datas')->insertGetId(['user_id' => $other->id, 'type' => 'file']);
        DB::table('shared_datas')->insert([
            ['user_id' => $other->id, 'data_id' => $owned], ['user_id' => $user->id, 'data_id' => $retained],
        ]);
        DB::table('upload_chunk_sessions')->insert(['user_id' => $user->id, 'data_id' => $owned]);
        DB::table('upload_batches')->insert(['user_id' => $user->id]);
        $this->action('force_delete', [$user->id])->assertOk()->assertJsonPath('data.affected', 1);
        $this->assertNull(User::withTrashed()->find($user->id));
        $this->assertDatabaseMissing('datas', ['id' => $owned]);
        $this->assertDatabaseHas('datas', ['id' => $retained]);
        foreach (['personal_access_tokens', 'shared_datas', 'upload_chunk_sessions', 'upload_batches'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNotNull($other->fresh());
    }

    public function test_non_admin_cannot_use_bulk_endpoint(): void
    {
        $this->actingAs($this->account('member'), 'sanctum');
        $this->action('delete', [$this->admin->id])->assertForbidden();
    }

    public function test_database_failure_rolls_back_all_selected_accounts(): void
    {
        $first = $this->account('first');
        $second = $this->account('second');
        Schema::drop('activity_log');
        $this->action('delete', [$first->id, $second->id])->assertStatus(500);
        $this->assertFalse($first->fresh()->trashed());
        $this->assertFalse($second->fresh()->trashed());
    }
}
