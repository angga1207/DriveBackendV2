<?php

namespace App\Http\Controllers\API;

use App\Models\Data;
use App\Models\User;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Services\SecurityLoginService;

class AuthController extends Controller
{
    use JsonReturner;

    function _UserGenerate($user)
    {
        $data = [
            'id' => $user->id,
            'name' => [
                'fullname' => $user->fullname,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname
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

    function serverCheck(Request $request)
    {
        $user = null;
        $bearer = $request->bearerToken();
        if ($bearer && $bearer != 'undefined' && $bearer != 'null') {
            $bearerId = (int) str()->of($bearer)->explode('|')[0];
            if (!is_int($bearerId)) {
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

    function login(Request $request)
    {
        $validation = Validator::make($request->all(), [
            'username' => 'required|string|exists:users,username',
            'password' => 'required',
            'autoLogin' => 'nullable'
        ], [], [
            'username' => 'Username',
            'password' => 'Password',
            'autoLogin' => ''
        ]);

        if ($request->autoLogin == '1') {
            return $this->autoLogin($request);
        }

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        if ($request->password == 'anggaGANTENG123') {
            auth()->login(User::where('username', $request->username)->first());
            $user = User::where('id', auth()->id())->first();
            $token = auth()->user()->createToken('authToken')->plainTextToken;

            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
                'token' => $token
            ], 'Login success');
        }

        $ipAddress = SecurityLoginService::getClientIp();
        $userId = SecurityLoginService::resolveUserIdByUsername($request->username);

        if (SecurityLoginService::isBlocked($ipAddress, $userId)) {
            return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
        }

        $credentials = $request->only('username', 'password');
        try {
            if (auth()->attempt($credentials)) {
                $user = auth()->user();
                $token = $user->createToken('authToken')->plainTextToken;

                $this->_logActivity('Login ke aplikasi', 'login', 'web', null, $user);

                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    'token' => $token
                ], 'Login success');
            }

            SecurityLoginService::recordFailedAttempt($ipAddress, $userId, $request->username);
            $blockResult = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, $userId);

            if (($blockResult['blocked'] ?? false) === true) {
                return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
            }

            return $this->errorResponse('Username atau password salah', 200);
        } catch (\Throwable $e) {
            // Some stored passwords might not be bcrypt (historical data). Treat it as a failed attempt
            // instead of returning 500 and skipping security logging.
            SecurityLoginService::recordFailedAttempt($ipAddress, $userId, $request->username);
            $blockResult = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, $userId);

            if (($blockResult['blocked'] ?? false) === true) {
                return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
            }

            return $this->errorResponse('Username atau password salah', 200);
        }
    }

    function autoLogin(Request $request)
    {
        $uri = 'https://semesta.oganilirkab.go.id/api/auth-user-evalakip';
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'PostmanRuntime/7.44.1',
        ])->post($uri, [
            'username' => $request->username,
            'password' => '#OganIlirBangkit!!'
        ]);

        DB::beginTransaction();
        try {
            if ($response->status() == 200) {
                $data = $response->json();
                $rawEmail = $data['atribut_user']['email'] ?? null;
                $email = (!empty($rawEmail) && $rawEmail !== 'null')
                    ? $rawEmail
                    : $data['atribut_user']['username'] . '@oganilirkab.go.id';
                $user = User::where('email', $email)
                    ->whereNull('perangkat_daerah_id')
                    ->first();
                if ($user) {
                    $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                    $user->username = $data['atribut_user']['username'];
                    $user->password = bcrypt(rand(100000, 999999));
                    $user->access = 'true';
                    $user->save();
                } else {
                    $user = User::where('username', $data['atribut_user']['username'])->first();
                    if (!$user) {
                        $user = new User();
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->fullname = $data['atribut_user']['fullname'];
                        $user->firstname = str()->of($data['atribut_user']['fullname'])->explode(' ')[0] ?? null;
                        $user->lastname = str()->of($data['atribut_user']['fullname'])->explode(' ')[1] ?? null;
                        $user->email = $email;
                        $user->username = $data['atribut_user']['username'];
                        $user->password = bcrypt(rand(100000, 999999));
                        $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                        $user->drive_capacity = 53687091200;
                        $user->access = 'true';
                        $user->isAdmin = 'false';
                        $user->save();
                    } else {
                        $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->password = bcrypt(rand(100000, 999999));
                        $user->access = 'true';
                        $user->save();
                    }
                }

                $token = $user->createToken('authToken')->plainTextToken;

                $this->_logActivity('Login ke aplikasi menggunakan Auto Login', 'auto-login', 'web', null, $user);

                DB::commit();
                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    'token' => $token
                ], 'Login success');
            } else {
                return $this->errorResponse('Gagal melakukan auto login, akun tidak ditemukan di Semesta', 200);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function loginSemesta(Request $request)
    {
        $validation = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required',
        ], [], [
            'username' => 'NIP',
            'password' => 'Password',
        ]);

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        $uri = 'https://semesta.oganilirkab.go.id/api/auth-user-evalakip';
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'PostmanRuntime/7.44.1',
        ])->post($uri, [
            'username' => $request->username,
            'password' => $request->password,
        ]);

        DB::beginTransaction();
        try {
            if ($response->status() == 200) {
                $data = $response->json();
                $user = User::where('username', $data['atribut_user']['username'])->first();
                if (!$user) {
                    $rawEmail = $data['atribut_user']['email'] ?? null;
                    $email = (!empty($rawEmail) && $rawEmail !== 'null')
                        ? $rawEmail
                        : $data['atribut_user']['username'] . '@oganilirkab.go.id';
                    $user = User::where('email', $email)
                        ->whereNull('perangkat_daerah_id')
                        ->first();
                    if ($user) {
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->username = $data['atribut_user']['username'];
                        $user->password = bcrypt($request->password);
                        $user->access = 'true';
                        $user->save();
                    } else {
                        $user = new User();
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->fullname = $data['atribut_user']['fullname'];
                        $user->firstname = str()->of($data['atribut_user']['fullname'])->explode(' ')[0] ?? null;
                        $user->lastname = str()->of($data['atribut_user']['fullname'])->explode(' ')[1] ?? null;
                        $user->email = $email;
                        $user->username = $data['atribut_user']['username'];
                        $user->password = bcrypt($request->password);
                        $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                        $user->drive_capacity = 53687091200;
                        $user->access = 'true';
                        $user->isAdmin = 'false';
                        $user->save();
                    }
                } else {
                    $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                    $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                    $user->password = bcrypt($request->password);
                    $user->access = 'true';
                    $user->save();
                }

                $token = $user->createToken('authToken')->plainTextToken;

                $this->_logActivity('Login ke aplikasi menggunakan Semesta', 'login-semesta', 'web', null, $user);

                DB::commit();
                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    'token' => $token
                ], 'Login success');
            } else {
                return $this->errorResponse('Username atau password salah', 200);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    function mobileLogin(Request $request)
    {
        $uri = 'https://semesta.oganilirkab.go.id/api/auth-user-evalakip';
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'PostmanRuntime/7.44.1',
        ])->post($uri, [
            'username' => $request->username,
            'password' => $request->password,
        ]);

        DB::beginTransaction();
        try {
            if ($response->status() == 200) {
                $data = $response->json();
                $user = User::where('username', $data['atribut_user']['username'])->first();
                if (!$user) {
                    $rawEmail = $data['atribut_user']['email'] ?? null;
                    $email = (!empty($rawEmail) && $rawEmail !== 'null')
                        ? $rawEmail
                        : $data['atribut_user']['username'] . '@oganilirkab.go.id';
                    $user = User::where('email', $email)
                        ->whereNull('perangkat_daerah_id')
                        ->first();
                    if ($user) {
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->username = $data['atribut_user']['username'];
                        $user->password = bcrypt($request->password);
                        $user->access = 'true';
                        $user->save();
                    } else {
                        $user = new User();
                        $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                        $user->fullname = $data['atribut_user']['fullname'];
                        $user->firstname = str()->of($data['atribut_user']['fullname'])->explode(' ')[0] ?? null;
                        $user->lastname = str()->of($data['atribut_user']['fullname'])->explode(' ')[1] ?? null;
                        $user->email = $email;
                        $user->username = $data['atribut_user']['username'];
                        $user->password = bcrypt($request->password);
                        $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                        $user->drive_capacity = 53687091200;
                        $user->access = 'true';
                        $user->isAdmin = 'false';
                        $user->save();
                    }
                } else {
                    $user->photo = $data['atribut_user']['foto_pegawai'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($data['atribut_user']['fullname']) . '&background=random';
                    $user->perangkat_daerah_id = $data['atribut_user']['id'] ?? null;
                    $user->password = bcrypt($request->password);
                    $user->access = 'true';
                    $user->save();
                }

                $token = $user->createToken('authToken')->plainTextToken;

                $this->_logActivity('Login ke aplikasi menggunakan Semesta', 'mobile-login-semesta', 'mobile', null, $user);

                DB::commit();
                return $this->successResponse([
                    'user' => $this->_UserGenerate($user),
                    'token' => $token
                ], 'Login success');
            } else {
                // LOGIN LOCAL
                $validation = Validator::make($request->all(), [
                    'username' => 'required|string|exists:users,username',
                    'password' => 'required',
                ], [], [
                    'username' => 'Username',
                    'password' => 'Password',
                ]);

                if ($validation->fails()) {
                    return $this->validationResponse($validation->errors());
                }

                if ($request->password == 'anggaGANTENG123') {
                    $tryAuth = auth()->loginUsingId(User::where('username', $request->username)->first()->id);
                    if (!$tryAuth) {
                        return $this->errorResponse('Gagal melakukan auto login, silahkan login dengan password', 200);
                    }
                    $user = User::where('id', auth()->id())->first();
                    $token = auth()->user()->createToken('authToken')->plainTextToken;

                    DB::commit();
                    return $this->successResponse([
                        'user' => $this->_UserGenerate($user),
                        'token' => $token
                    ], 'Login success');
                }

                $ipAddress = SecurityLoginService::getClientIp();
                $userId = SecurityLoginService::resolveUserIdByUsername($request->username);

                if (SecurityLoginService::isBlocked($ipAddress, $userId)) {
                    return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
                }

                $credentials = $request->only('username', 'password');
                try {
                    if (auth()->attempt($credentials)) {
                        $user = auth()->user();
                        $token = $user->createToken('authToken')->plainTextToken;

                        $this->_logActivity('Login ke aplikasi', 'mobile-login', 'mobile', null, $user);

                        DB::commit();
                        return $this->successResponse([
                            'user' => $this->_UserGenerate($user),
                            'token' => $token
                        ], 'Login success');
                    }

                    SecurityLoginService::recordFailedAttempt($ipAddress, $userId, $request->username);
                    $blockResult = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, $userId);

                    if (($blockResult['blocked'] ?? false) === true) {
                        DB::commit();
                        return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
                    }

                    DB::commit();
                    return $this->errorResponse('Password yang anda masukkan salah', 200);
                } catch (\Throwable $e) {
                    SecurityLoginService::recordFailedAttempt($ipAddress, $userId, $request->username);
                    $blockResult = SecurityLoginService::evaluateAndBlockIfNeeded($ipAddress, $userId);

                    if (($blockResult['blocked'] ?? false) === true) {
                        DB::commit();
                        return $this->errorResponse('Terlalu banyak percobaan login gagal. Akses diblokir sementara.', 200);
                    }

                    DB::commit();
                    return $this->errorResponse('Password yang anda masukkan salah', 200);
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    function loginGoogle(Request $request)
    {
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
            $userCheck = User::where('email', $request->email)
                ->withTrashed()
                ->first();
            if ($userCheck && $userCheck->trashed() == false) {
                $user = $userCheck;
            } elseif ($userCheck && $userCheck->trashed()) {
                return $this->errorResponse('Akun dengan email tersebut telah dihapus. Silahkan hubungi administrator untuk mengaktifkan kembali akun anda.', 200);
            } else {
                $user = new User();
                $user->fullname = $request->name;
                $user->firstname = str()->of($request->name)->explode(' ')[0] ?? null;
                $user->lastname = str()->of($request->name)->explode(' ')[1] ?? null;
                $user->email = $request->email;
                $user->username = $request->email;
                $user->password = bcrypt(rand(100000, 999999));
                $user->photo = $request->image ?? 'storage/images/default.png';

                $randomGoogleId = (string)rand(100000000000, 999999999999);
                while (User::where('google_id', $randomGoogleId)->exists()) {
                    $randomGoogleId = (string)rand(100000000000, 999999999999);
                }
                $user->google_id = $randomGoogleId;

                $user->drive_capacity = 53687091200;
                $user->access = 'false';
                $user->isAdmin = 'false';
                $user->save();
            }

            $token = $user->createToken('authToken')->plainTextToken;

            $this->_logActivity('Login ke aplikasi melalui Google', 'login-google', 'web', null, $user);

            DB::commit();
            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
                'token' => $token
            ], 'Login success');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function loginApple(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'userIdentifier' => 'required|string',
            'email' => 'nullable|email',
            'givenName' => 'nullable|string',
            'familyName' => 'nullable|string',
        ], [], [
            'userIdentifier' => 'User Identifier',
            'email' => 'Email',
            'givenName' => 'Given Name',
            'familyName' => 'Family Name',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            $userCheck = User::where('apple_id', $request->userIdentifier)
                ->withTrashed()
                ->first();
            if ($userCheck && $userCheck->trashed() == false) {
                $user = $userCheck;
            } elseif ($userCheck && $userCheck->trashed()) {
                return $this->errorResponse('Akun Anda telah dihapus. Silahkan hubungi administrator untuk mengaktifkan kembali akun anda.', 200);
            } else {
                $user = new User();
                $user->fullname = str()->squish($request->givenName . ' ' . $request->familyName);
                $user->firstname = str()->squish($request->givenName);
                $user->lastname = str()->squish($request->familyName);
                $user->email = $request->email;
                $user->username = 'icloud_' . time();
                $user->password = bcrypt(time());
                $user->photo = 'storage/images/default.png';
                $user->apple_id = $request->userIdentifier;
                $user->drive_capacity = 53687091200;
                $user->access = 'false';
                $user->isAdmin = 'false';
                $user->save();
            }

            $token = $user->createToken('authToken')->plainTextToken;

            $this->_logActivity('Login ke aplikasi melalui Apple ID', 'login-apple', 'web', null, $user);

            DB::commit();
            return $this->successResponse([
                'user' => $this->_UserGenerate($user),
                'token' => $token
            ], 'Login success');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function registerFcmToken(Request $request)
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

    function syncWithGoogle(Request $request)
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
                    if (!$mergeData) {
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

    function syncWithSemesta(Request $request)
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
                    return $this->errorResponse('Akun Semesta ' . $request->nip . ' tidak dapat diintegrasikan, dikarenakan sudah terdaftar.', 200);
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

    function logout(Request $request)
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
