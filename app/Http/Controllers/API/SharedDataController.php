<?php

namespace App\Http\Controllers\API;

use Carbon\Carbon;
use App\Models\Data;
use App\Models\SharedData;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataResource;
use Illuminate\Support\Facades\Validator;

class SharedDataController extends Controller
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

    function getItemsSharer(Request $request)
    {
        try {
            $data = [];
            if (!$request->slug || $request->slug == 0 || $request->slug == '0') {
                return $this->successResponse(DataResource::collection($data), 200);
            }

            $parent = Data::where('slug', $request->slug)
                ->where('shared', 'public')
                ->where('status', 'active')
                ->first();
            if (!$parent) {
                return $this->errorResponse('Data not found', 200);
            }

            if ($parent->type == 'folder') {
                $data = Data::when($request->slug != 0, function ($q) use ($parent) {
                    $q->where('parent_id', $parent->id);
                })
                    ->with(['Childs', 'User', 'Parent'])
                    ->where('status', 'active')
                    ->orderBy('type', 'desc')
                    ->orderBy('name')
                    ->get();
                return $this->successResponse(DataResource::collection($data), 200);
            } elseif ($parent->type == 'file') {
                $data = Data::where('slug', $request->slug)
                    ->with(['Childs', 'User', 'Parent'])
                    ->where('status', 'active')
                    ->get();
                return $this->successResponse(DataResource::collection($data), 200);
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getItemsSharerV2(Request $request)
    {
        try {
            $data = [];
            if (!$request->slug || $request->slug == 0 || $request->slug == '0') {
                return $this->successResponse(DataResource::collection($data), 200);
            }

            $parent = Data::where('slug', $request->slug)
                ->where('shared', 'public')
                ->where('status', 'active')
                ->first();
            if (!$parent) {
                return $this->errorResponse('Data not found', 200);
            }

            if ($parent->type == 'folder') {
                $data = Data::when($request->slug != 0, function ($q) use ($parent) {
                    $q->where('parent_id', $parent->id);
                })
                    ->with(['Childs', 'User', 'Parent'])
                    ->where('status', 'active')
                    ->orderBy('type', 'desc')
                    ->orderBy('name')
                    ->paginate($request->per_page ?? 10);
                return $this->successResponse([
                    'data' => DataResource::collection($data),
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ], 200);
            } elseif ($parent->type == 'file') {
                $data = Data::where('slug', $request->slug)
                    ->with(['Childs', 'User', 'Parent'])
                    ->where('status', 'active')
                    ->paginate($request->per_page ?? 10);
                return $this->successResponse([
                    'data' => DataResource::collection($data),
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ], 200);
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function setPublicity($slug, Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $validation = Validator::make($request->all(), [
            'slug' => 'required|exists:datas,slug',
            'data.publicity.status' => 'required|string|in:private,public',
            'data.publicity.expired_at' => 'nullable|date|required_if:data.publicity.status,public|after:now',
            'data.publicity.editable' => 'nullable|boolean',
        ], [
            'data.publicity.expired_at.after' => 'Tanggal kadaluarsa harus lebih besar dari sekarang',
        ], [
            'data.publicity.status' => 'Status',
            'data.publicity.expired_at' => 'Tanggal Kadaluarsa',
            'data.publicity.editable' => 'Dapat Diedit',
        ]);

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        DB::beginTransaction();
        try {
            $requestData = $request->data;
            $data = Data::where('slug', $slug)
                ->where('status', 'active')
                ->first();
            if (!$data) {
                return $this->errorResponse('Data not found', 200);
            }
            $data->shared = $requestData['publicity']['status'] ?? 'private';
            if ($requestData['publicity']['status'] == 'private') {
                $data->expired_at = null;
            }
            if ($requestData['publicity']['status'] == 'public') {
                $data->expired_at = $requestData['publicity']['expired_at'] ?? Carbon::now()->addHour()->format('Y-m-d H:i:00');
            }
            if ($requestData['publicity']['forever'] == true) {
                $data->expired_at = '9999-12-31 23:59:59';
            }
            $isEditable = 0;
            if (isset($requestData['publicity']['editable'])) {
                $isEditable = $requestData['publicity']['editable'] == true ? 1 : 0;
            } else {
                $isEditable = 0;
            }
            $data->s_editable = $isEditable;

            $isShared = $data->shared;
            if ($isShared == 'private') {
                $data->expired_at = null;
                $data->s_editable = 0;
            }
            $data->save();

            $descendants = $data->descendants()->get();
            foreach ($descendants as $descendant) {
                $descendant->shared = $data->shared;
                $descendant->expired_at = $data->expired_at;
                $descendant->s_editable = $data->s_editable;
                $descendant->save();
            }

            $message = 'Merubah status ' . $data->type . ' ' . $data->name . ' menjadi ' . $data->shared;
            $this->_logActivity($message, 'publicity-' . $data->type, 'web', $data);

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Publicity item berhasil dirubah!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getSharedFolders(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }
        try {
            $sharedDataIds = SharedData::where('user_id', auth()->id())
                ->pluck('data_id')
                ->toArray();

            $data = Data::with([
                'User:id,fullname,firstname,lastname,email,photo',
                'Parent:id,slug,name'
            ])
                ->withCount(['Childs as childs_count' => function ($query) {
                    $query->where('status', 'active');
                }])
                ->whereIn('id', $sharedDataIds)
                ->where('type', 'folder')
                ->where('status', 'active')
                ->orderBy('name')
                ->where('shared', 'public')
                ->where('s_editable', true)
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getSharedFoldersV2(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }
        try {
            $sharedDataIds = SharedData::where('user_id', auth()->id())
                ->pluck('data_id')
                ->toArray();

            $data = Data::with([
                'User:id,fullname,firstname,lastname,email,photo',
                'Parent:id,slug,name'
            ])
                ->withCount(['Childs as childs_count' => function ($query) {
                    $query->where('status', 'active');
                }])
                ->whereIn('id', $sharedDataIds)
                ->where('type', 'folder')
                ->where('status', 'active')
                ->orderBy('name')
                ->where('shared', 'public')
                ->where('s_editable', true)
                ->paginate($request->per_page ?? 10);

            return $this->successResponse([
                'data' => DataResource::collection($data),
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getAccessToFolder(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $validation = Validator::make($request->all(), [
            'slug' => 'required|string|exists:datas,slug',
        ], [], [
            'slug' => 'Nama Item',
        ]);

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        DB::beginTransaction();
        try {
            $data = Data::where('slug', $request->slug)
                ->where('shared', 'public')
                ->where('status', 'active')
                ->first();
            if (!$data) {
                return $this->errorResponse('Data not found', 200);
            }

            $existingAccess = SharedData::where('data_id', $data->id)
                ->where('user_id', auth()->id())
                ->first();
            if ($existingAccess) {
                return $this->errorResponse('Anda sudah memiliki akses ke folder ini.', 200);
            }

            $sharedData = new SharedData();
            $sharedData->data_id = $data->id;
            $sharedData->user_id = auth()->id();
            $sharedData->access_type = $data->access_type ?? 'write';
            $sharedData->save();

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Akses folder berhasil ditambahkan!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }
}
