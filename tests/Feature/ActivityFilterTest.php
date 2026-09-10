<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSessionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityFilterTest extends TestCase
{
    // Skema dasar disimpan di database/migrations/old, jadi kedua path harus dimuat.
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--path' => ['database/migrations/old', 'database/migrations']]);
        $this->createActivityLogTable();
    }

    /**
     * Migrasi activity_log milik paket Spatie tidak pernah dipublikasikan ke project,
     * jadi tabelnya dibangun di sini agar cocok dengan skema yang dipakai aplikasi.
     */
    private function createActivityLogTable(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    private function user(string $username = 'uji.aktivitas'): User
    {
        return User::forceCreate([
            'name' => $username, 'fullname' => 'Uji Aktivitas', 'firstname' => 'Uji', 'lastname' => 'Aktivitas',
            'username' => $username, 'email' => $username.'@example.test',
            'password' => bcrypt('rahasia123'), 'access' => 'true', 'isAdmin' => 'false',
        ]);
    }

    private function log(User $user, string $event, string $description): void
    {
        Activity::create([
            'log_name' => 'default',
            'description' => $description,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => ['ip' => '127.0.0.1', 'agent' => 'phpunit', 'type' => 'web', 'event' => $event],
        ]);
    }

    private function bearer(User $user): array
    {
        $request = Request::create('/api/login', 'POST');
        $request->headers->set('X-Device-Id', str_repeat('a', 32));
        $session = app(DeviceSessionService::class)->issue($user, $request);

        return ['Authorization' => 'Bearer '.$session['token']];
    }

    public function test_unfiltered_list_returns_every_activity_of_the_user(): void
    {
        $user = $this->user();
        $this->log($user, 'upload-file', 'Unggah A');
        $this->log($user, 'create-folder', 'Buat folder B');

        $payload = $this->getJson('/api/getActivities', $this->bearer($user))->assertOk()->json('data');

        $this->assertSame(2, $payload['total']);
        $this->assertCount(2, $payload['data']);
    }

    public function test_filter_narrows_results_to_the_requested_event(): void
    {
        $user = $this->user();
        $this->log($user, 'upload-file', 'Unggah A');
        $this->log($user, 'upload-file', 'Unggah B');
        $this->log($user, 'create-folder', 'Buat folder C');

        $payload = $this->getJson('/api/getActivities?event=upload-file', $this->bearer($user))->assertOk()->json('data');

        $this->assertSame(2, $payload['total']);
        $this->assertSame(['upload-file', 'upload-file'], array_column($payload['data'], 'event'));
    }

    public function test_event_options_cover_all_pages_not_just_the_visible_one(): void
    {
        $user = $this->user();
        // Halaman pertama memuat 10 item; jenis 'share-item' hanya ada di halaman kedua.
        foreach (range(1, 12) as $index) {
            $this->log($user, 'upload-file', "Unggah {$index}");
        }
        $this->log($user, 'share-item', 'Bagikan Z');

        $payload = $this->getJson('/api/getActivities', $this->bearer($user))->assertOk()->json('data');

        $this->assertCount(10, $payload['data']);
        $this->assertEqualsCanonicalizing(['share-item', 'upload-file'], $payload['events']);
    }

    public function test_filter_and_options_never_leak_other_users_activity(): void
    {
        $user = $this->user();
        $other = $this->user('pengguna.lain');
        $this->log($user, 'upload-file', 'Milik saya');
        $this->log($other, 'force-delete-item', 'Milik orang lain');

        $payload = $this->getJson('/api/getActivities', $this->bearer($user))->assertOk()->json('data');

        $this->assertSame(1, $payload['total']);
        $this->assertSame(['upload-file'], $payload['events']);

        $filtered = $this->getJson('/api/getActivities?event=force-delete-item', $this->bearer($user))->assertOk()->json('data');
        $this->assertSame(0, $filtered['total']);
    }

    public function test_blank_event_parameter_is_treated_as_no_filter(): void
    {
        $user = $this->user();
        $this->log($user, 'upload-file', 'Unggah A');

        $payload = $this->getJson('/api/getActivities?event=%20', $this->bearer($user))->assertOk()->json('data');

        $this->assertSame(1, $payload['total']);
    }
}
