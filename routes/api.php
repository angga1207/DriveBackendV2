<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DataController;
use App\Http\Controllers\API\SharedDataController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\UploadController;
use App\Http\Controllers\API\SecurityLoginController;
use App\Http\Controllers\API\AdminInsightsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Drive OI V2 API Routes - Compatible dengan Frontend Next.js
|
*/

// Server health check
Route::get('/a12', [AuthController::class, 'serverCheck']);
Route::post('/a12', [AuthController::class, 'serverCheck']);

// Public routes (no authentication)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/mobile-login', [AuthController::class, 'mobileLogin']);
Route::post('/auto-login', [AuthController::class, 'autoLogin']);
Route::post('/login/semesta', [AuthController::class, 'loginSemesta']);
Route::post('/login/google', [AuthController::class, 'loginGoogle']);
Route::post('/login/apple', [AuthController::class, 'loginApple']);

// Public shared items (no auth required)
Route::get('/getItemsSharer', [SharedDataController::class, 'getItemsSharer']);
Route::get('/v2/getItemsSharer', [SharedDataController::class, 'getItemsSharerV2']);
Route::get('/path', [DataController::class, 'getPublicPath']);
Route::post('/download', [DataController::class, 'postDownload']);

// Evalakip Upload (API key protected, no auth)
Route::post('/evalakip/Upload', [UploadController::class, 'postUploadEvalakip']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {

    // Security (login brute force protection)
    Route::post('/v2/security/reset-login-security', [SecurityLoginController::class, 'resetLoginSecurity']);

    // SL Blocks
    Route::get('/v2/security/get-blocked-login-security', [SecurityLoginController::class, 'getBlockedLoginSecurity']);
    Route::post('/v2/security/restore-login-security', [SecurityLoginController::class, 'restoreLoginSecurity']);

    // SL Attempts
    Route::get('/v2/security/get-login-attempts', [SecurityLoginController::class, 'getLoginAttemptsSecurity']);
    Route::post('/v2/security/restore-login-attempts', [SecurityLoginController::class, 'restoreLoginAttemptsSecurity']);

    Route::get('/profile/sessions/current', [\App\Http\Controllers\API\SessionController::class, 'current']);
    Route::get('/profile/sessions', [\App\Http\Controllers\API\SessionController::class, 'index']);
    Route::delete('/profile/sessions/others', [\App\Http\Controllers\API\SessionController::class, 'destroyOthers']);
    Route::delete('/profile/sessions/{id}', [\App\Http\Controllers\API\SessionController::class, 'destroy'])->whereNumber('id');

    // Auth & Profile
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/getProfile', [ProfileController::class, 'getProfile']);
    Route::post('/updateProfile', [ProfileController::class, 'updateProfile']);
    Route::get('/getActivities', [ProfileController::class, 'getActivities']);
    Route::post('/deleteMySelf', [ProfileController::class, 'deleteMySelf']);

    // FCM Token
    Route::post('/fcm', [AuthController::class, 'registerFcmToken']);

    // Sync
    Route::post('/sync/google', [AuthController::class, 'syncWithGoogle']);
    Route::post('/sync/semesta', [AuthController::class, 'syncWithSemesta']);

    // File & Folder Management
    Route::get('/latestFiles', [DataController::class, 'getLatestFiles']);
    Route::get('/getPath', [DataController::class, 'getPath']);

    // Get Items (with pagination v2)
    Route::get('/getItems', [DataController::class, 'getItems']);
    Route::get('/v2/getItems', [DataController::class, 'getItemsV2']);

    Route::get('/getFolders', [DataController::class, 'getFolders']);
    Route::post('/folder', [DataController::class, 'createFolder']);

    // File Operations
    Route::post('/rename/{slug}', [DataController::class, 'rename']);
    Route::post('/moveItem', [DataController::class, 'moveItem']);
    Route::post('/delete', [DataController::class, 'softDelete']);
    Route::post('/restore', [DataController::class, 'restore']);
    Route::post('/forceDelete', [DataController::class, 'forceDelete']);

    // Upload
    Route::post('/upload/{folderSlug}', [UploadController::class, 'uploadFiles']);
    Route::post('/upload-in-folder/{parentSlug}', [UploadController::class, 'uploadInFolder']);

    // Chunk upload (for large files, e.g. > 50MB)
    Route::post('/upload-chunk/init/{folderSlug}', [UploadController::class, 'uploadChunkInit']);
    Route::post('/upload-chunk/part', [UploadController::class, 'uploadChunkPart']);
    Route::post('/upload-chunk/complete', [UploadController::class, 'uploadChunkComplete']);

    Route::get('/getUploadQueue', [UploadController::class, 'getUploadQueue']);

    // Favorites
    Route::post('/setFavorite', [DataController::class, 'setFavorite']);
    Route::get('/getFavoriteItems', [DataController::class, 'getFavoriteItems']);
    Route::get('/v2/getFavoriteItems', [DataController::class, 'getFavoriteItemsV2']);

    // Trash
    Route::get('/getItemsTrashed', [DataController::class, 'getItemsTrashed']);
    Route::get('/v2/getItemsTrashed', [DataController::class, 'getItemsTrashedV2']);

    // Sharing & Publicity
    Route::post('/publicity/{slug}', [SharedDataController::class, 'setPublicity']);
    Route::get('/getSharedFolders', [SharedDataController::class, 'getSharedFolders']);
    Route::get('/v2/getSharedFolders', [SharedDataController::class, 'getSharedFoldersV2']);
    Route::post('/getAccessToFolder', [SharedDataController::class, 'getAccessToFolder']);

    // Search
    Route::get('/search', [SearchController::class, 'search']);

    Route::post('/v2/admin/users/actions', [\App\Http\Controllers\API\UserLifecycleController::class, 'bulk'])->middleware(\App\Http\Middleware\AdminMiddleware::class);

    // User Management
    Route::get('/getUsers', [UserController::class, 'getUsers'])->middleware(\App\Http\Middleware\AdminMiddleware::class);
    Route::get('/v2/getUsers', [UserController::class, 'getUsersV2'])->middleware(\App\Http\Middleware\AdminMiddleware::class);
    Route::post('/createUser', [UserController::class, 'createUser'])->middleware(\App\Http\Middleware\AdminMiddleware::class);
    Route::post('/updateUser/{id}', [UserController::class, 'updateUser'])->middleware(\App\Http\Middleware\AdminMiddleware::class);
    Route::post('/updateUserAccess/{id}', [UserController::class, 'updateUserAccess'])->middleware(\App\Http\Middleware\AdminMiddleware::class);
    Route::delete('/deleteUser/{id}', [UserController::class, 'deleteUser'])->middleware(\App\Http\Middleware\AdminMiddleware::class);

    // Administrative reporting (database admin permission)
    Route::get('/v2/admin/analytics', [AdminInsightsController::class, 'analytics']);
    Route::get('/v2/admin/activities', [AdminInsightsController::class, 'activities']);
});
