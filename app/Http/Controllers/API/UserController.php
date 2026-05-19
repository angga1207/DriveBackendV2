<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use App\Models\Data;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Validator;
use App\Notifications\FirebaseNotification;
use Illuminate\Support\Facades\Notification;

class UserController extends Controller
{
    use JsonReturner;

    function getUsers(Request $request)
    {
        try {
            $users = User::search($request->search)
                ->where('status', 'active')
                ->whereIn('access', ['true', 'false'])
                ->orderBy('access', 'desc')
                ->oldest('id')
                ->get();

            return $this->successResponse(UserResource::collection($users), 'Users', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getUsersV2(Request $request)
    {
        try {
            // Batch all stat counts into a single query
            $statsQuery = User::query()
                ->selectRaw("
                    COUNT(CASE WHEN perangkat_daerah_id IS NOT NULL THEN 1 END) as semesta_count,
                    COUNT(CASE WHEN google_id IS NOT NULL THEN 1 END) as google_count,
                    COUNT(CASE WHEN google_id IS NOT NULL AND perangkat_daerah_id IS NOT NULL THEN 1 END) as both_count,
                    COUNT(CASE WHEN google_id IS NULL AND perangkat_daerah_id IS NULL THEN 1 END) as none_count,
                    COUNT(CASE WHEN access = 'true' THEN 1 END) as accessed_count,
                    COUNT(CASE WHEN access = 'false' THEN 1 END) as unaccessed_count
                ")
                ->first();

            $users = User::search($request->search)
                ->where('status', 'active')
                ->whereIn('access', ['true', 'false'])
                // Eager load aggregates to avoid N+1 queries in UserResource
                ->addSelect(['drive_size_sum' => Data::selectRaw('COALESCE(SUM(size), 0)')
                    ->whereColumn('user_id', 'users.id')
                    ->where('type', 'file')
                    ->whereNull('temp_path')
                    ->whereNull('deleted_at')
                ])
                ->withCount([
                    'MyDrive as files_count' => function ($q) {
                        $q->where('type', 'file')
                            ->whereNull('deleted_at');
                    },
                    'MyDrive as folders_count' => function ($q) {
                        $q->where('type', 'folder')
                            ->whereNull('temp_path')
                            ->whereNull('deleted_at');
                    },
                    'MyDrive as shared_count' => function ($q) {
                        $q->where('shared', 'public')
                            ->whereNull('deleted_at');
                    },
                ])
                ->when($request->order_by, function ($query) use ($request) {
                    if ($request->order_by == 'drive_usage') {
                        $query->orderBy('drive_size_sum', $request->order_direction ?? 'desc');
                    } else {
                        $query->orderBy($request->order_by, $request->order_direction ?? 'desc');
                    }
                })
                ->orderBy('access', 'desc')
                ->paginate($request->per_page ?? 10);

            return $this->successResponse([
                'data' => UserResource::collection($users),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),

                'accessed_users_count' => $statsQuery->accessed_count ?? 0,
                'unaccessed_users_count' => $statsQuery->unaccessed_count ?? 0,
                'google_users_count' => $statsQuery->google_count ?? 0,
                'semesta_users_count' => $statsQuery->semesta_count ?? 0,
                'google_and_semesta_users_count' => $statsQuery->both_count ?? 0,
                'no_integrated_users_count' => $statsQuery->none_count ?? 0,
            ], 'Users', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function createUser(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|unique:users,email',
            'username' => 'required|unique:users,username',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5000',
            'capacity' => 'required|numeric',
            'password' => 'required|string',
            'password_confirmation' => 'required|string|same:password',
        ], [], [
            'firstname' => 'Nama Depan',
            'lastname' => 'Nama Belakang',
            'email' => 'Email',
            'username' => 'Nama Pengguna',
            'photo' => 'Foto',
            'capacity' => 'Kapasitas',
            'password' => 'Password',
            'password_confirmation' => 'Konfirmasi Password',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        try {
            $user = new User();
            $user->firstname = $request->firstname;
            $user->lastname = $request->lastname;
            $user->fullname = $request->firstname . ' ' . $request->lastname;
            $user->email = $request->email;
            $user->username = $request->username;
            $user->password = bcrypt($request->password);
            $user->access = 'true';
            $user->isAdmin = 'false';

            if ($request->capacity) {
                $capacity = $request->capacity;
                $capacity = $capacity * 1024 * 1024 * 1024;
                $user->drive_capacity = $capacity;
            }

            if ($request->photo) {
                $photo = $request->photo;
                $photoName = $user->username . '.' . $photo->getClientOriginalExtension();
                $photo->move(public_path('storage/images'), $photoName);
                $user->photo = 'storage/images/' . $photoName;
            }

            $user->save();

            return $this->successResponse(new UserResource($user), 'Pengguna Berhasil Dibuat', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function updateUser($id, Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|exists:users,id',
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|unique:users,email,' . $id,
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5000',
            'capacity' => 'required|numeric',
            'password' => 'nullable|confirmed|string',
        ], [], [
            'id' => 'User',
            'firstname' => 'Nama Depan',
            'lastname' => 'Nama Belakang',
            'email' => 'Email',
            'photo' => 'Foto',
            'capacity' => 'Kapasitas',
            'password' => 'Password',
            'password_confirmation' => 'Konfirmasi Password',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        try {
            $user = User::find($id);
            $user->firstname = $request->firstname;
            $user->lastname = $request->lastname;
            $user->fullname = $request->firstname . ' ' . $request->lastname;
            $user->email = $request->email;

            if ($request->capacity) {
                $capacity = $request->capacity;
                $capacity = $capacity * 1024 * 1024 * 1024;
                $user->drive_capacity = $capacity;
            }

            if ($request->photo) {
                $photo = $request->photo;
                $photoName = $user->username . '.' . $photo->getClientOriginalExtension();
                $photo->move(public_path('storage/images'), $photoName);
                $user->photo = 'storage/images/' . $photoName;
            }

            if ($request->password) {
                $user->password = bcrypt($request->password);
            }
            $user->save();

            return $this->successResponse(new UserResource($user), 'Pengguna Berhasil Diperbarui', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function updateUserAccess($id, Request $request)
    {
        $validate = Validator::make($request->all(), [
            'access' => 'required|boolean',
        ], [], [
            'access' => 'Access',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        try {
            $user = User::find($id);
            if (!$user) {
                return $this->errorResponse('User not found', 200);
            }
            $user->access = $request->access ? 'true' : 'false';
            $user->save();

            if ($user->access == 'true') {
                Notification::send($user, new FirebaseNotification(
                    [$user->id],
                    'Akses Diberikan',
                    'Akses pada akun Anda telah diberikan. Silahkan login untuk mengakses layanan.',
                    null,
                ));
            } else {
                Notification::send($user, new FirebaseNotification(
                    [$user->id],
                    'Akses Dicabut',
                    'Akses pada akun Anda telah dicabut. Silahkan hubungi admin untuk informasi lebih lanjut.',
                    null,
                ));
            }

            return $this->successResponse(new UserResource($user), 'Akses Pengguna Berhasil Diperbarui', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function deleteUser($id, Request $request)
    {
        DB::beginTransaction();
        try {
            $user = User::find($id);
            if (!$user) {
                return $this->errorResponse('User not found', 200);
            }
            $user->delete();

            DB::commit();
            return $this->successResponse(null, 'Pengguna Berhasil Dihapus', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }
}
