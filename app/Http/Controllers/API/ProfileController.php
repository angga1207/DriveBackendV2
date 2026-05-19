<?php

namespace App\Http\Controllers\API;

use App\Models\Data;
use App\Models\User;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use App\Http\Resources\ActivityResource;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    use JsonReturner;

    private function _logActivity($message, $event, $type = 'web', $performedOn = null)
    {
        $log = activity();
        if ($performedOn) {
            $log->performedOn($performedOn);
        }
        $log->causedBy(auth()->user())
            ->withProperties([
                'ip' => request()->ip(),
                'agent' => request()->header('user-agent'),
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
                'user' => request()->userAgent(),
                'user_id' => auth()->id(),
                'type' => $type,
                'event' => $event,
            ])->log($message);
    }

    function getProfile(Request $request)
    {
        try {
            $user = User::find(auth()->id());
            return $this->successResponse(new UserResource($user), null, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function updateProfile(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'username' => 'required|unique:users,username,' . auth()->id(),
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5000',
            'password' => 'nullable|string',
            'password_confirmation' => 'nullable|string|same:password',
        ], [], [
            'firstname' => 'Nama Depan',
            'lastname' => 'Nama Belakang',
            'username' => 'Nama Pengguna',
            'photo' => 'Foto',
            'password' => 'Password',
            'password_confirmation' => 'Konfirmasi Password',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors()->first());
        }

        DB::beginTransaction();
        try {
            $user = User::find(auth()->id());
            $user->firstname = $request->firstname;
            $user->lastname = $request->lastname;
            $user->fullname = $request->firstname . ' ' . $request->lastname;
            $user->username = $request->username;

            if ($request->photo) {
                $photo = $request->photo;
                $photoName = $user->username . time() . '.' . $photo->getClientOriginalExtension();
                $photo->move(public_path('storage/images'), $photoName);
                $user->photo = 'storage/images/' . $photoName;
            }

            if ($request->password) {
                $user->password = bcrypt($request->password);
            }

            $user->save();

            $this->_logActivity('Mengubah profil', 'update-profile', 'web', $user);

            DB::commit();

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
                'semestaIntegrated' => $user->perangkat_daerah_id ? true : false,
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

            return $this->successResponse($data, 'Profil Berhasil Diperbarui', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getActivities(Request $request)
    {
        try {
            $datas = Activity::where('causer_id', auth()->user()->id)
                ->orderBy('created_at', 'desc')
                ->whereDate('created_at', '>=', now()->subDays(30))
                ->paginate(10);

            $return = [
                'data' => ActivityResource::collection($datas),
                'current_page' => $datas->currentPage(),
                'last_page' => $datas->lastPage(),
                'total' => $datas->total(),
            ];
            return $this->successResponse($return, 'Activities', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function deleteMySelf(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'password' => 'required|string',
        ], [], [
            'password' => 'Password',
        ]);
        if ($validate->fails()) {
            return $this->validationResponse($validate->errors()->first());
        }
        if (!Hash::check($request->password, auth()->user()->password)) {
            return $this->errorResponse('Password salah', 200);
        }

        DB::beginTransaction();
        try {
            $user = User::find(auth()->id());
            if (!$user) {
                return $this->errorResponse('User not found', 200);
            }
            if ($user->perangkat_daerah_id) {
                return $this->errorResponse('Akun terintegrasi dengan Semesta tidak dapat dihapus sendiri. Silahkan hubungi admin.', 200);
            }
            $user->delete();

            auth()->user()->tokens()->delete();

            DB::commit();
            return $this->successResponse(null, 'Akun Anda Berhasil Dihapus', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }
}
