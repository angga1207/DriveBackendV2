<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Data;
use App\Models\User;
use App\Services\SecurityLoginService;
use App\Traits\JsonReturner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use \App\Traits\AuthFailureResponses, JsonReturner;

    public function _UserGenerate($user)
    {
        $data = [
            'id' => $user->id,
            'name' => [
                'fullname' => $user->fullname,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
            ],
            'username' => $user->username,
            'email' => $user->email,
            'googleIntegated' => $user->google_id ? true : false,
            'google_id' => $user->google_id,
            'semestaIntegrated' => $user->perangkat_daerah_id ? true : false,
            'appleIntegrated' => $user->apple_id ? true : false,
            'photo' => asset($user->photo),
            'storage' => [
                'total' => Data::generateSize($user->drive_capacity),
                'used' => Data::generateSize($user->MyDriveSize()),
                'rest' => Data::generateSize($user->MyDriveRestCapacity()),
                'percent' => $user->drive_capacity ? (($user->MyDriveSize() / $user->drive_capacity) * 100) : 0,
            ],
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'isAdmin' => $user->hasAdminAccess(),
            'access' => $user->access == 'true' ? true : false,
        ];

        return $data;
    }

    private function _logActivity($message, $event, $type = 'web', $performedOn = null, $causer = null)
    {
        $log = activity();
        if ($performedOn) {
            $log->performedOn($performedOn);
        }
        $activityCauser = $causer ?? auth()->user();
        if ($activityCauser) {
            $log->causedBy($activityCauser);
        }
        $log->withProperties([
            'ip' => request()->ip(),
            'agent' => request()->header('x-forwarded-user-agent') ?: request()->userAgent(),
            'locale' => request()->header('accept-language'),
            'device' => request()->header('user-device'),
            'browser' => request()->header('user-browser'),
            'platform' => request()->header('user-platform'),
            'version' => request()->header('user-version'),
            'os' => request()->header('user-os'),
            'host' => request()->header('user-host'),
            'referer' => request()->header('referer'),
            'method' => request()->method(),
            'url' => request()->fullUrl(),
            'user' => request()->header('x-forwarded-user-agent') ?: request()->userAgent(),
            'user_id' => $activityCauser?->getKey(),
            'type' => $type,
            'event' => $event,
        ])->log($message);
    }

    public function serverCheck(Request $request)
    {
        $user = null;
        $bearer = $request->bearerToken();
        if ($bearer && $bearer != 'undefined' && $bearer != 'null') {
            $bearerId = (int) str()->of($bearer)->explode('|')[0];
            if (! is_int($bearerId)) {
                $bearerId = null;
            }
            if ($bearerId && $bearerId != 'undefined' && $bearerId != 'null') {
                $token = DB::table('personal_access_tokens')
                    ->where('id', $bearerId)
                    ->first();
                if ($token) {
                    $user = User::find($token->tokenable_id);
                }
                if ($user) {
                    $user = $this->_UserGenerate($user);

                    return $this->successResponse($user, 'Server is running');
                }
            }
        }

        return $this->successResponse(null, 'Server is running');
    }

    public function login(Request $request)
    {
        if ($request->autoLogin == '1') {
            return $this->autoLogin($request);
        }

        $validation = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
        ], ['username' => 'Username/NIP', 'password' => 'Kata sandi']);
        if ($validation->fails()) {
            return $this->authFailure('VALIDATION_FAILED', 422, $validation->errors()->toArray());
        }

        try {
            $user = User::withTrashed()->where('username', $request->username)->first();
            $ipAddress = SecurityLoginService::getClientIp();
            $userId = $user?->id;
            if (SecurityLoginService::isBlocked($ipAddress, $userId)) {
                return $this->authFailure('LOGIN_BLOCKED');
            }
            if (! $user) {
                SecurityLoginService::recordFailedAttempt($ipAddress, null, $request->username);
                $blocked = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, null);

                return $this->authFailure(($blocked['blocked'] ?? false) ? 'LOGIN_BLOCKED' : 'ACCOUNT_NOT_FOUND');
            }

            try {
                $validPassword = Hash::check($request->password, $user->password);
            } catch (\RuntimeException $exception) {
                $validPassword = false;
            }
            if (! $validPassword) {
                SecurityLoginService::recordFailedAttempt($ipAddress, $userId, $request->username);
                $blocked = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, $userId);

                return $this->authFailure(($blocked['blocked'] ?? false) ? 'LOGIN_BLOCKED' : 'INVALID_CREDENTIALS');
            }

            return DB::transaction(function () use ($user, $request) {
                $user = User::withTrashed()->lockForUpdate()->findOrFail($user->id);
                if (! $this->restoreAccessibleAccount($user)) {
                    return $this->authFailure('ACCOUNT_DELETED');
                }
                $session = app(\App\Services\DeviceSessionService::class)->issue($user, $request);
                $this->_logActivity('Login ke aplikasi', 'login', 'web', null, $user);

                return $this->successResponse(['user' => $this->_UserGenerate($user), ...$session], 'Login berhasil');
            });
        } catch (\Throwable $exception) {
            return $this->authException($exception);
        }
    }

    private function restoreAccessibleAccount(User $user): bool
    {
        if (! $user->trashed()) {
            return true;
        }
        if (! in_array($user->access, [true, 'true', 1, '1'], true)) {
            return false;
        }
        $user->restore();
        // Tokens issued before deletion must not become valid again on restore.
        $user->tokens()->delete();

        return true;
    }

    public function autoLogin(Request $request)
    {
        return $this->semestaLogin($request, true);
    }

    public function loginSemesta(Request $request)
    {
        return $this->semestaLogin($request);
    }

    public function mobileLogin(Request $request)
    {
        return $this->semestaLogin($request, false, true);
    }

    private function semestaLogin(Request $request, bool $automatic = false, bool $localFallback = false)
    {
        $validation = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => $automatic ? 'nullable|string' : 'required|string',
        ], ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.'], [
            'username' => 'Username/NIP', 'password' => 'Kata sandi',
        ]);
        if ($validation->fails()) {
            return $this->authFailure('VALIDATION_FAILED', 422, $validation->errors()->toArray());
        }

        try {
            $response = Http::acceptJson()->withUserAgent('PostmanRuntime/7.44.1')
                ->connectTimeout(5)->timeout(15)
                ->post('https://semesta.oganilirkab.go.id/api/auth-user-evalakip', [
                    'username' => $request->username,
                    'password' => $automatic ? config('services.semesta.auto_login_password') : $request->password,
                ]);
        } catch (ConnectionException $exception) {
            return $this->authFailure('PROVIDER_UNAVAILABLE', 503);
        }

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                return $this->authFailure('PROVIDER_UNAVAILABLE', 503);
            }

            return $localFallback ? $this->login($request) : $this->authFailure('INVALID_CREDENTIALS');
        }
        $identity = $response->json('atribut_user');
        if (! is_array($identity) || empty($identity['username']) || empty($identity['fullname'])) {
            return $this->authFailure('PROVIDER_INVALID_RESPONSE', 502);
        }
        $email = ! empty($identity['email']) && $identity['email'] !== 'null'
            ? $identity['email'] : $identity['username'].'@oganilirkab.go.id';

        try {
            return DB::transaction(function () use ($identity, $email, $request, $automatic, $localFallback) {
                $matches = User::withTrashed()->where(function ($query) use ($identity, $email) {
                    $query->where('username', $identity['username'])->orWhere('email', $email);
                    if (! empty($identity['id'])) {
                        $query->orWhere('perangkat_daerah_id', (string) $identity['id']);
                    }
                })->lockForUpdate()->get();
                if ($matches->count() > 1) {
                    return $this->authFailure('ACCOUNT_CONFLICT', 409);
                }
                $user = $matches->first();
                if ($user && ! $this->restoreAccessibleAccount($user)) {
                    return $this->authFailure('ACCOUNT_DELETED');
                }
                if (! $user) {
                    $user = new User;
                    $user->fullname = $identity['fullname'];
                    $names = explode(' ', $identity['fullname'], 2);
                    $user->firstname = $names[0];
                    $user->lastname = $names[1] ?? '';
                    $user->email = $email;
                    $user->drive_capacity = 53687091200;
                    $user->isAdmin = 'false';
                }
                $user->username = $identity['username'];
                $user->perangkat_daerah_id = $identity['id'] ?? null;
                $user->password = bcrypt($automatic ? Str::random(40) : $request->password);
                $user->photo = $identity['foto_pegawai'] ?? 'storage/images/default.png';
                $user->access = 'true';
                $user->save();
                $session = app(\App\Services\DeviceSessionService::class)->issue($user, $request);
                $this->_logActivity('Login ke aplikasi menggunakan Semesta', $automatic ? 'auto-login' : 'login-semesta', $localFallback ? 'mobile' : 'web', null, $user);

                return $this->successResponse(['user' => $this->_UserGenerate($user), ...$session], 'Login berhasil');
            });
        } catch (\Throwable $exception) {
            return $this->authException($exception);
        }
    }

    public function loginGoogle(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email',
            'image' => 'nullable|url',
        ], ['required' => ':attribute wajib diisi.', 'email' => 'Alamat email tidak valid.', 'url' => ':attribute harus berupa URL yang valid.', 'string' => ':attribute harus berupa teks.'], [
            'name' => 'Nama',
            'email' => 'Email',
            'image' => 'Foto',
        ]);

        if ($validate->fails()) {
            return $this->authFailure('VALIDATION_FAILED', 422, $validate->errors()->toArray());
        }

        try {
            return DB::transaction(function () use ($request) {
                $userCheck = User::where('email', $request->email)
                    ->withTrashed()
                    ->lockForUpdate()
                    ->first();
                if ($userCheck) {
                    if (! $this->restoreAccessibleAccount($userCheck)) {
                        return $this->authFailure('ACCOUNT_DELETED');
                    }
                    $user = $userCheck;
                } else {
                    if (User::withTrashed()->where('username', $request->email)->exists()) {
                        return $this->authFailure('ACCOUNT_CONFLICT', 409);
                    }
                    $user = new User;
                    $user->fullname = $request->name;
                    $user->firstname = str()->of($request->name)->explode(' ')[0] ?? null;
                    $user->lastname = str()->of($request->name)->explode(' ')[1] ?? null;
                    $user->email = $request->email;
                    $user->username = $request->email;
                    $user->password = bcrypt(rand(100000, 999999));
                    $user->photo = $request->image ?? 'storage/images/default.png';

                    $randomGoogleId = (string) rand(100000000000, 999999999999);
                    while (User::where('google_id', $randomGoogleId)->exists()) {
                        $randomGoogleId = (string) rand(100000000000, 999999999999);
                    }
                    $user->google_id = $randomGoogleId;

                    $user->drive_capacity = 53687091200;
                    $user->access = 'false';
                    $user->isAdmin = 'false';
                    $user->save();
                }

                $session = app(\App\Services\DeviceSessionService::class)->issue($user, $request);

                $this->_logActivity('Login ke aplikasi melalui Google', 'login-google', 'web', null, $user);

                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    ...$session,
                ], 'Login success');
            });
        } catch (\Throwable $e) {
            return $this->authException($e);
        }
    }

    public function loginApple(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'userIdentifier' => 'required|string',
            'email' => 'nullable|email',
            'givenName' => 'nullable|string',
            'familyName' => 'nullable|string',
        ], ['required' => ':attribute wajib diisi.', 'email' => 'Alamat email tidak valid.', 'url' => ':attribute harus berupa URL yang valid.', 'string' => ':attribute harus berupa teks.'], [
            'userIdentifier' => 'User Identifier',
            'email' => 'Email',
            'givenName' => 'Given Name',
            'familyName' => 'Family Name',
        ]);

        if ($validate->fails()) {
            return $this->authFailure('VALIDATION_FAILED', 422, $validate->errors()->toArray());
        }

        try {
            return DB::transaction(function () use ($request) {
                $userCheck = User::where('apple_id', $request->userIdentifier)
                    ->withTrashed()
                    ->lockForUpdate()
                    ->first();
                if ($userCheck) {
                    if (! $this->restoreAccessibleAccount($userCheck)) {
                        return $this->authFailure('ACCOUNT_DELETED');
                    }
                    $user = $userCheck;
                } else {
                    if ($request->email && User::withTrashed()->where('email', $request->email)->exists()) {
                        $existing = User::withTrashed()->where('email', $request->email)->first();

                        return $this->authFailure($existing->trashed() && ! in_array($existing->access, [true, 'true', 1, '1'], true) ? 'ACCOUNT_DELETED' : 'ACCOUNT_CONFLICT', 409);
                    }
                    if (! $request->email || ! $request->givenName) {
                        return $this->authFailure('PROVIDER_INVALID_RESPONSE', 422);
                    }
                    $user = new User;
                    $user->fullname = str()->squish($request->givenName.' '.$request->familyName);
                    $user->firstname = str()->squish($request->givenName);
                    $user->lastname = str()->squish($request->familyName);
                    $user->email = $request->email;
                    $user->username = 'icloud_'.time();
                    $user->password = bcrypt(time());
                    $user->photo = 'storage/images/default.png';
                    $user->apple_id = $request->userIdentifier;
                    $user->drive_capacity = 53687091200;
                    $user->access = 'false';
                    $user->isAdmin = 'false';
                    $user->save();
                }

                $session = app(\App\Services\DeviceSessionService::class)->issue($user, $request);

                $this->_logActivity('Login ke aplikasi melalui Apple ID', 'login-apple', 'web', null, $user);

                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    ...$session,
                ], 'Login success');
            });
        } catch (\Throwable $e) {
            return $this->authException($e);
        }
    }

    public function registerFcmToken(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'token' => 'required|string',
        ], [], [
            'token' => 'FCM Token',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        try {
            $user = User::find(auth()->id());
            $user->fcm_token = $request->token;
            $user->save();

            return $this->successResponse(null, 'FCM Token berhasil disimpan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    public function syncWithGoogle(Request $request)
    {
        $user = User::find(auth()->id());
        if ($user->google_id !== null && $user->google_id != '' && $user->google_id != 0) {
            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
            ], 'Akun anda sudah terintegrasi dengan Google, Tidak dapat diintegrasikan lagi dengan akun yang lainnya.', 200);
        }

        $validate = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email',
            'image' => 'nullable|url',
        ], [], [
            'name' => 'Name',
            'email' => 'Email',
            'image' => 'Image',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            if (auth()->user()->perangkat_daerah_id) {
                $userCheck = User::where('email', $request->email)
                    ->where('id', '!=', $user->id)
                    ->first();
                if ($userCheck) {
                    $mergeData = $this->_MergeTwoAccounts($userCheck, $user);
                    if (! $mergeData) {
                        return $this->errorResponse('Gagal mengintegrasikan akun dengan Google, silahkan coba lagi.', 200);
                    }
                    $userCheck->forceDelete();
                }
            } else {
                $userCheck = User::where('email', $request->email)
                    ->where('id', '!=', $user->id)
                    ->first();
                if ($userCheck) {
                    return $this->errorResponse('Google ID sudah terdaftar di akun lain', 200);
                }
            }

            $user->email = $request->email;
            $user->google_id = (string) rand(100000000000, 999999999999);
            $user->save();

            $this->_logActivity('Integrasi akun dengan Google', 'sync-google');

            DB::commit();

            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
            ], 'Akun berhasil diintegrasikan dengan Google');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    public function syncWithSemesta(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'nip' => 'required|string',
            'password' => 'required|string',
        ], [], [
            'nip' => 'NIP',
            'password' => 'Password',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $user = User::find(auth()->id());
        if ($user->perangkat_daerah_id !== null && $user->perangkat_daerah_id != '' && $user->perangkat_daerah_id != 0) {
            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
            ], 'Akun anda sudah terintegrasi dengan Semesta, tidak dapat diintegrasikan lagi.', 200);
        }

        $uri = 'https://semesta.oganilirkab.go.id/api/auth-user-evalakip';
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'PostmanRuntime/7.44.1',
        ])->post($uri, [
            'username' => $request->nip,
            'password' => $request->password,
        ]);

        DB::beginTransaction();
        try {
            if ($response->status() == 200) {
                $data = $response->json();

                $checkExists = User::where('perangkat_daerah_id', $data['atribut_user']['id'])->first();
                if ($checkExists) {
                    return $this->errorResponse('Akun Semesta '.$request->nip.' tidak dapat diintegrasikan, dikarenakan sudah terdaftar.', 200);
                }

                $user = User::find(auth()->id());
                if ($user) {
                    $user->username = $data['atribut_user']['username'] ?? $user->username;
                    $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                    $user->save();
                }
            } else {
                return $this->errorResponse('Gagal mengintegrasikan akun dengan Semesta', 200);
            }

            $this->_logActivity('Integrasi akun dengan Semesta', 'sync-semesta');

            DB::commit();

            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
            ], 'Akun berhasil diintegrasikan dengan Semesta', 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->successResponse(null, 'Logout success');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    private function _MergeTwoAccounts($oldAccount, $newAccount)
    {
        DB::beginTransaction();
        try {
            Data::where('user_id', $oldAccount->id)
                ->update(['user_id' => $newAccount->id]);
            DB::commit();

            return 1;
        } catch (\Exception $e) {
            DB::rollBack();

            return 0;
        }
    }
}
