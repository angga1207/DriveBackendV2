# 📘 Drive OI Backend V2 - Implementation Guide

Panduan lengkap untuk menyelesaikan implementasi backend baru.

## 📋 Status Saat Ini

### ✅ Sudah Selesai

1. **Project Structure** - Laravel 11 fresh install
2. **Dependencies** - Semua package terinstall:
   - Laravel Sanctum (API Auth)
   - Spatie Permission (Role-based access)
   - Spatie Activity Log (Audit logging)
   - Kalnoy Nestedset (Hierarchical folders)
   - Google Drive integration
   - Firebase/FCM

3. **Database Migrations** ✅
   - `2024_01_01_000001_update_users_table.php`
   - `2024_01_02_000000_create_ref_perangkat_daerah_table.php`
   - `2024_01_03_000000_create_datas_table.php`
   - `2024_01_04_000000_create_shared_datas_table.php`
   - `2024_01_05_000000_create_upload_batches_table.php`

4. **Models** ✅
   - `User.php` - dengan storage helpers
   - `Data.php` - nested set + scopes
   - `SharedData.php` - sharing logic
   - `UploadBatch.php` - batch tracking
   - `RefPerangkatDaerah.php`

5. **API Resources** ✅
   - `UserResource.php`
   - `DataResource.php`
   - `SharedDataResource.php`
   - `UploadBatchResource.php`
   - `RefPerangkatDaerahResource.php`

6. **Routes** ✅
   - `routes/api.php` - Semua endpoint terdefinisi

7. **Configuration** ✅
   - `config/cors.php` - CORS setup
   - `.env.example` - Environment template

### 🚧 Yang Perlu Diselesaikan

1. **Controllers** (Priority 1)
2. **Services** (Priority 1)
3. **Form Requests** (Priority 2)
4. **Jobs & Events** (Priority 2)
5. **Middleware** (Priority 2)
6. **Configuration Files** (Priority 3)

---

## 🎯 Implementation Steps

### STEP 1: Generate Controller Skeletons

Run the setup script atau generate manually:

```bash
cd DriveBackendV2

# Generate controllers
php artisan make:controller API/AuthController
php artisan make:controller API/DataController
php artisan make:controller API/SharedDataController
php artisan make:controller API/UserController
php artisan make:controller API/ProfileController
php artisan make:controller API/SearchController
php artisan make:controller API/UploadController
```

### STEP 2: Implement Controllers

Gunakan pattern berikut untuk setiap controller:

#### **AuthController.php** - Template

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Server health check
     */
    public function serverCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Server is running',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Login - Standard username/password
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user has access
        if (!$user->hasAccess()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account access has been revoked. Please contact administrator.',
            ], 403);
        }

        // Create token
        $token = $user->createToken('api-token')->plainTextToken;

        // Log activity
        activity()
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('User logged in');

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load('perangkatDaerah')),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Mobile Login (same as login, different endpoint for compatibility)
     */
    public function mobileLogin(Request $request): JsonResponse
    {
        return $this->login($request);
    }

    /**
     * Auto Login dengan token
     */
    public function autoLogin(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccess()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account access has been revoked.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => new UserResource($user->load('perangkatDaerah')),
            ],
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Log activity
        activity()
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('User logged out');

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Google OAuth Login
     */
    public function loginGoogle(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
            'google_id' => 'required|string',
            'photo' => 'nullable|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Create new user
            $user = User::create([
                'fullname' => $request->name,
                'email' => $request->email,
                'username' => strtolower(str_replace(' ', '', $request->name)) . rand(1000, 9999),
                'google_id' => $request->google_id,
                'photo' => $request->photo ?? 'storage/images/default.png',
                'password' => Hash::make(uniqid()),
                'status' => 'pending', // Need admin approval
            ]);
        } else {
            // Update google_id if not set
            if (!$user->google_id) {
                $user->update(['google_id' => $request->google_id]);
            }
        }

        if (!$user->hasAccess()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account is pending approval or access has been revoked.',
            ], 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load('perangkatDaerah')),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Register FCM Token for push notifications
     */
    public function registerFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $user = $request->user();
        $user->update(['fcm_token' => $request->fcm_token]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCM token registered',
        ]);
    }

    // TODO: Implement other methods
    // - loginSemesta()
    // - loginApple()
    // - syncWithGoogle()
    // - syncWithSemesta()
}
```

#### **DataController.php** - Sample Methods

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Data;
use App\Http\Resources\DataResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DataController extends Controller
{
    /**
     * Get Items - dengan eager loading dan optimization
     */
    public function getItems(Request $request): JsonResponse
    {
        $slug = $request->input('slug');
        $userId = $request->user()->id;

        // Get parent folder
        $parent = null;
        if ($slug) {
            $parent = Data::where('slug', $slug)
                ->where(function ($query) use ($userId) {
                    $query->where('user_id', $userId)
                        ->orWhere('shared', 'public')
                        ->orWhereHas('sharedWith', fn($q) => $q->where('user_id', $userId));
                })
                ->firstOrFail();
        }

        // Query items dengan optimization
        $query = Data::with(['user:id,fullname,username,photo', 'uploadBatch:id,status,progress_percentage'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if ($parent) {
            $query->where('parent_id', $parent->id);
        } else {
            $query->whereNull('parent_id');
        }

        $items = $query->orderBy('type', 'desc') // Folders first
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => DataResource::collection($items),
            'parent' => $parent ? new DataResource($parent) : null,
        ]);
    }

    /**
     * Get Items V2 - dengan pagination
     */
    public function getItemsV2(Request $request): JsonResponse
    {
        $slug = $request->input('slug');
        $perPage = min($request->input('per_page', 25), 100);
        $userId = $request->user()->id;

        $parent = null;
        if ($slug) {
            $parent = Data::where('slug', $slug)->firstOrFail();
        }

        $query = Data::with(['user:id,fullname,username', 'uploadBatch:id,status'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if ($parent) {
            $query->where('parent_id', $parent->id);
        } else {
            $query->whereNull('parent_id');
        }

        $items = $query->orderBy('type', 'desc')
            ->orderBy('name', 'asc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => DataResource::collection($items),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'parent' => $parent ? new DataResource($parent) : null,
        ]);
    }

    /**
     * Create Folder
     */
    public function createFolder(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_slug' => 'nullable|string|exists:datas,slug',
        ]);

        $user = $request->user();
        $parentId = null;

        if ($request->parent_slug) {
            $parent = Data::where('slug', $request->parent_slug)
                ->where('user_id', $user->id)
                ->firstOrFail();
            $parentId = $parent->id;
        }

        // Check duplicate name in same folder
        $exists = Data::where('name', $request->name)
            ->where('parent_id', $parentId)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Folder with this name already exists in this location',
            ], 422);
        }

        $folder = Data::create([
            'name' => $request->name,
            'type' => 'folder',
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'status' => 'active',
            'store_to' => 'local',
        ]);

        // Log activity
        activity()
            ->causedBy($user)
            ->performedOn($folder)
            ->log('Created folder: ' . $folder->name);

        return response()->json([
            'status' => 'success',
           'message' => 'Folder created successfully',
            'data' => new DataResource($folder),
        ], 201);
    }

    /**
     * Rename Item
     */
    public function rename(string $slug, Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $item = Data::where('slug', $slug)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $oldName = $item->name;
        $item->update(['name' => $request->name]);

        activity()
            ->causedBy($user)
            ->performedOn($item)
            ->withProperties(['old_name' => $oldName, 'new_name' => $request->name])
            ->log('Renamed: ' . $oldName . ' to ' . $request->name);

        return response()->json([
            'status' => 'success',
            'message' => 'Item renamed successfully',
            'data' => new DataResource($item),
        ]);
    }

    /**
     * Soft Delete
     */
    public function softDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'string|exists:datas,slug',
        ]);

        $user = $request->user();
        $items = Data::whereIn('slug', $request->ids)
            ->where('user_id', $user->id)
            ->get();

        foreach ($items as $item) {
            $item->delete(); // Soft delete

            activity()
                ->causedBy($user)
                ->performedOn($item)
                ->log('Moved to trash: ' . $item->name);
        }

        return response()->json([
            'status' => 'success',
            'message' => count($items) . ' item(s) moved to trash',
        ]);
    }

    // TODO: Implement other methods
    // - getPath()
    // - getLatestFiles()
    // - moveItem()
    // - setFavorite()
    // - getFavoriteItems()
    // - getItemsTrashed()
    // - restore()
    // - forceDelete()
    // - getFolders()
}
```

### STEP 3: Create Services (Business Logic Layer)

Create `app/Services/DataService.php`:

```php
<?php

namespace App\Services;

use App\Models\Data;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class DataService
{
    /**
     * Calculate folder size recursively
     */
    public function calculateFolderSize(Data $folder): int
    {
        if ($folder->isFile()) {
            return $folder->size;
        }

        return $folder->descendants()
            ->where('type', 'file')
            ->sum('size');
    }

    /**
     * Check if user can access data
     */
    public function canUserAccess(User $user, Data $data): bool
    {
        // Owner can always access
        if ($data->user_id === $user->id) {
            return true;
        }

        // Check if public and not expired
        if ($data->isPublic() && $data->canBeAccessed()) {
            return true;
        }

        // Check if shared with user
        return $data->isSharedWith($user->id);
    }

    /**
     * Move item to different folder
     */
    public function moveItem(Data $item, ?int $newParentId): bool
    {
        // Validate not moving folder into itself or its descendants
        if ($item->isFolder() && $newParentId) {
            $newParent = Data::find($newParentId);
            if ($newParent && $newParent->isDescendantOf($item)) {
                return false;
            }
        }

        $item->parent_id = $newParentId;
        return $item->save();
    }

    /**
     * Get user's storage usage
     */
    public function getUserStorageUsage(User $user): array
    {
        $totalSize = $user->datas()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->sum('size');

        return [
            'used' => $totalSize,
            'capacity' => $user->drive_capacity,
            'remaining' => max(0, $user->drive_capacity - $totalSize),
            'percentage' => $user->drive_capacity > 0 
                ? round(($totalSize / $user->drive_capacity) * 100, 2) 
                : 0,
        ];
    }
}
```

### STEP 4: Create Jobs for Background Processing

Generate jobs:

```bash
php artisan make:job TransferFileToGoogleDrive
php artisan make:job ProcessUploadBatch
php artisan make:job CleanupExpiredShares
```

**TransferFileToGoogleDrive.php** example:

```php
<?php

namespace App\Jobs;

use App\Models\Data;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Exception;

class TransferFileToGoogleDrive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Data $data
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Skip if already uploaded or flagged to skip
        if ($this->data->store_to === 'google' || $this->data->skip_upload_to_google) {
            return;
        }

        try {
            // Get file from temp_path
            if (!$this->data->temp_path || !Storage::disk('local')->exists($this->data->temp_path)) {
                throw new Exception('Temp file not found');
            }

            $fileContent = Storage::disk('local')->get($this->data->temp_path);
            
            // Generate Google Drive path
            $gdPath = sprintf(
                'files_2/%d/%s/%s',
                $this->data->user_id,
                now()->format('Y-m-d'),
                $this->data->name
            );

            // Upload to Google Drive
            Storage::disk('google')->put($gdPath, $fileContent);

            // Update data record
            $this->data->update([
                'path' => $gdPath,
                'store_to' => 'google',
                'temp_path' => null,
            ]);

            // Delete temp file
            Storage::disk('local')->delete($this->data->temp_path);

            // Update batch progress
            if ($this->data->upload_batch_id) {
                $this->data->uploadBatch->incrementProcessed();
            }

        } catch (Exception $e) {
            \Log::error('Failed to upload to Google Drive', [
                'data_id' => $this->data->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Will retry based on $tries
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        \Log::error('Google Drive upload failed permanently', [
            'data_id' => $this->data->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark upload batch as failed
        if ($this->data->upload_batch_id) {
            $this->data->uploadBatch->markAsFailed();
        }
    }
}
```

### STEP 5: Create Middleware

Generate middleware:

```bash
php artisan make:middleware CheckUserAccess
php artisan make:middleware EnsureUserIsAdmin
```

**CheckUserAccess.php**:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckUserAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->hasAccess()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Your account has been restricted.',
            ], 403);
        }

        return $next($request);
    }
}
```

**EnsureUserIsAdmin.php**:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->isAdministrator()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
```

Register in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'check.access' => \App\Http\Middleware\CheckUserAccess::class,
        'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
    ]);
})
```

### STEP 6: Configure Environment

Copy `.env.example` to `.env` and configure:

1. Database credentials
2. Google Drive API
3. Firebase/FCM credentials
4. Frontend URL for CORS

### STEP 7: Run Migrations

**HATI-HATI:** Gunakan database production yang sudah ada!

```bash
# Check migration status first
php artisan migrate:status

# Run new migrations (hanya tambahan)
php artisan migrate

# Jika error, rollback specific migration
php artisan migrate:rollback --step=1
```

### STEP 8: Test API

1. Start development server:
```bash
php artisan serve
```

2. Test dengan Postman/Thunder Client
3. Verify semua endpoint berfungsi

### STEP 9: Deploy to Production

1. Setup queue worker (systemd/supervisor)
2. Configure web server (Nginx/Apache)
3. Setup SSL certificate
4. Configure firewall
5. Monitor logs

---

## 🔍 Testing Checklist

- [ ] POST /api/mobile-login - Login works
- [ ] GET /api/getProfile - Get user data
- [ ] GET /api/v2/getItems - List files/folders
- [ ] POST /api/folder - Create folder
- [ ] POST /api/rename/{slug} - Rename item
- [ ] POST /api/upload/{slug} - Upload file
- [ ] POST /api/delete - Soft delete
- [ ] POST /api/restore - Restore from trash
- [ ] GET /api/v2/getFavoriteItems - Get favorites
- [ ] POST /api/setFavorite - Toggle favorite
- [ ] GET /api/search - Search items
- [ ] POST /api/publicity/{slug} - Share item
- [ ] GET /api/v2/getSharedFolders - Get shared items

---

## 📞 Next Actions

1. **Complete Controllers** - Implement semua methods sesuai pattern di atas
2. **Add Validation** - Create Form Request classes
3. **Add Tests** - Unit & feature tests
4. **Document API** - Generate OpenAPI/Swagger docs
5. **Monitor Performance** - Add query logging
6. **Security Audit** - Check authorization logic

---

**Good Luck! 🚀**
