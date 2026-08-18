<?php

namespace App\Http\Controllers\API;

use Carbon\Carbon;
use App\Models\Data;
use App\Models\User;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class DataController extends Controller
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
                'user' => request()->userAgent(),
                'user_id' => auth()->id(),
                'type' => $type,
                'event' => $event,
            ])->log($message);
    }

    function getPath(Request $request)
    {
        try {
            $data = [];
            $current = null;
            if ($request->slug) {
                if ($request->slug !== 0) {
                    $current = Data::where('slug', $request->slug)
                        ->where('user_id', auth()->id())
                        ->first();
                    if ($current) {
                        $current = new DataResource($current);
                        $data = Data::ancestorsOf($current->id);
                        $data = DataResource::collection($data);
                    }
                }
            }

            return $this->successResponse([
                'paths' => $data,
                'current' => $current ?? '',
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getPublicPath(Request $request)
    {
        try {
            $data = [];
            $current = null;
            if ($request->slug) {
                if ($request->slug !== 0) {
                    $current = Data::where('slug', $request->slug)
                        ->where('shared', 'public')
                        ->first();
                    if ($current) {
                        $current = new DataResource($current);
                        $ancestors = Data::ancestorsOf($current->id);
                        // Filter to only show public ancestors
                        $data = $ancestors->filter(fn($item) => $item->shared === 'public');
                        $data = DataResource::collection($data);
                    }
                }
            }

            return $this->successResponse([
                'paths' => $data,
                'current' => $current ?? '',
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getItems(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }
        try {
            $slug = $request->slug;
            $isRootFolder = ($slug === '0' || $slug === 0 || $slug === null || $slug === '');

            $parent = null;
            if (!$isRootFolder) {
                $parent = Data::where('slug', $slug)
                    ->where('type', 'folder')
                    ->where('status', 'active')
                    ->where('user_id', auth()->id())
                    ->first();
            }

            if ($isRootFolder && !$parent) {
                $data = Data::with([
                    'User:id,fullname,firstname,lastname,email,photo',
                    'Parent:id,slug,name'
                ])
                    ->withCount(['Childs as childs_count' => function ($query) {
                        $query->where('status', 'active');
                    }])
                    ->where('status', 'active')
                    ->where('user_id', auth()->id())
                    ->whereNull('parent_id')
                    ->orderBy('type', 'desc')
                    ->orderBy('name')
                    ->get();

                return $this->successResponse(DataResource::collection($data), 200);
            }

            if (!$isRootFolder && $parent) {
                $data = Data::with([
                    'User:id,fullname,firstname,lastname,email,photo',
                    'Parent:id,slug,name'
                ])
                    ->withCount(['Childs as childs_count' => function ($query) {
                        $query->where('status', 'active');
                    }])
                    ->where('parent_id', $parent->id)
                    ->where('status', 'active')
                    ->orderBy('type', 'desc')
                    ->orderBy('name')
                    ->get();

                return $this->successResponse(DataResource::collection($data), 200);
            }

            if (!$isRootFolder && !$parent) {
                return $this->errorResponse('Bukan Folder Anda!', 200);
            }

            return $this->successResponse([], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getItemsV2(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }
        try {
            $slug = $request->slug;
            $isRootFolder = ($slug === '0' || $slug === 0 || $slug === null || $slug === '');

            $parent = null;
            if (!$isRootFolder) {
                $parent = Data::where('slug', $slug)
                    ->where('type', 'folder')
                    ->where('status', 'active')
                    ->where('user_id', auth()->id())
                    ->first();
            }

            if ($isRootFolder && !$parent) {
                $data = Data::with([
                    'User:id,fullname,firstname,lastname,email,photo',
                    'Parent:id,slug,name'
                ])
                    ->withCount(['Childs as childs_count' => function ($query) {
                        $query->where('status', 'active');
                    }])
                    ->where('status', 'active')
                    ->where('user_id', auth()->id())
                    ->whereNull('parent_id')
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
            }

            if (!$isRootFolder && $parent) {
                $data = Data::with([
                    'User:id,fullname,firstname,lastname,email,photo',
                    'Parent:id,slug,name'
                ])
                    ->withCount(['Childs as childs_count' => function ($query) {
                        $query->where('status', 'active');
                    }])
                    ->where('parent_id', $parent->id)
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
            }

            if (!$isRootFolder && !$parent) {
                return $this->errorResponse('Bukan Folder Anda!', 200);
            }

            return $this->successResponse([], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getLatestFiles(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        try {
            $data = Data::where('user_id', auth()->id())
                ->where('status', 'active')
                ->where('type', 'file')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getFolders(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }
        try {
            $parent = Data::where('slug', $request->slug)
                ->where('type', 'folder')
                ->where('status', 'active')
                ->first();

            $data = Data::where('type', 'folder')
                ->where('status', 'active')
                ->where('user_id', auth()->id())
                ->when($parent, function ($q) use ($parent) {
                    $q->where('parent_id', $parent->id);
                })
                ->when($request->slug == 0 || !$request->slug, function ($q) {
                    $q->whereNull('parent_id');
                })
                ->whereNotIn('slug', $request->excludeIds ?? [])
                ->get();

            $path = [];
            $currentPath = null;
            if ($parent) {
                $path = Data::find($parent->id)->ancestors;
                $path = DataResource::collection($path);
                $currentPath = new DataResource($parent);
            }

            return $this->successResponse([
                'path' => $path,
                'currentPath' => $currentPath,
                'folders' => DataResource::collection($data),
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function createFolder(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $validation = Validator::make($request->all(), [
            'name' => 'required|string',
            'parent_slug' => 'nullable',
        ], [], [
            'name' => 'Nama Folder',
            'parent_slug' => 'Parent Folder',
        ]);

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        DB::beginTransaction();
        try {
            $parent = null;
            if ($request->parent_slug && $request->parent_slug != 0 && $request->parent_slug != '0' && $request->parent_slug != 'null' && $request->parent_slug != null) {
                $parent = Data::where('slug', $request->parent_slug)
                    ->where('status', 'active')
                    ->where('type', 'folder')
                    ->first();

                if ($parent) {
                    if ($parent->shared == 'private') {
                        if ($parent->user_id != auth()->id()) {
                            return $this->errorResponse('Anda tidak memiliki akses menambah folder di path ini!', 200);
                        }
                    } else {
                        if ((auth()->id() != $parent->user_id) && ($parent->s_editable == false)) {
                            return $this->errorResponse('Anda tidak memiliki akses menambah folder di path ini!', 200);
                        }
                    }
                }

                if (!$parent) {
                    return $this->errorResponse('Parent folder salah! Perhatikan path saat membuat folder baru!', 200);
                }
            }

            $data = new Data();
            $data->name = $request->name;
            $data->slug = $data->generateSlug();
            $data->type = 'folder';
            $data->store_to = 'local';
            $data->status = 'active';
            $data->user_id = auth()->id();
            $data->pd_id = null;
            $data->size = 0;
            $data->extension = null;
            $data->mimes = 'folder';
            $data->temp_path = null;

            $parentShared = $parent->shared ?? 'private';
            $data->shared = $parentShared;
            if ($parentShared == 'public') {
                $data->expired_at = $parent->expired_at;
                $data->s_editable = $parent->s_editable;
            }

            if ($parent) {
                $data->appendToNode($parent);
                $data->save();
            } else {
                $data->saveAsRoot();
            }

            $this->_logActivity('Membuat ' . $data->type . ' ' . $data->name, 'create-folder-' . $data->type, 'web', $data);

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Folder berhasil dibuat!', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function rename($slug, Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $validation = Validator::make($request->all(), [
            'name' => 'required|string',
        ], [], [
            'name' => 'Nama Item',
        ]);

        if ($validation->fails()) {
            return $this->validationResponse($validation->errors());
        }

        DB::beginTransaction();
        try {
            $data = Data::where('slug', $slug)
                ->where('status', 'active')
                ->first();

            if (!$data) {
                return $this->errorResponse('Data not found', 200);
            }

            if ($data->shared == 'private') {
                if ($data->user_id != auth()->id()) {
                    return $this->errorResponse('Anda tidak memiliki akses untuk merubah nama item ini.', 200);
                }
            } else {
                if ((auth()->id() != $data->user_id) && ($data->s_editable == false)) {
                    return $this->errorResponse('Data ini tidak dapat diunggah berkas karena hanya dapat dilihat saja.', 200);
                }
            }

            $message = 'Merubah nama ' . $data->type . ' ' . $data->name . ' menjadi ' . $request->name;
            $data->name = $request->name;
            $data->save();

            $this->_logActivity($message, 'rename-' . $data->type, 'web', $data);

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Nama item berhasil dirubah!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function moveItem(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'sourceIds' => 'required|array',
            'targetId' => 'required',
        ], [], [
            'sourceIds' => 'Item',
            'targetId' => 'Folder Tujuan',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            if ($request->targetId != 0) {
                $target = Data::where('slug', $request->targetId)
                    ->whereNotIn('slug', $request->sourceIds)
                    ->where('user_id', auth()->id())
                    ->where('status', 'active')
                    ->where('type', 'folder')
                    ->first();

                if (!$target) {
                    return $this->errorResponse('Folder tujuan tidak ditemukan', 200);
                }

                $source = Data::whereIn('slug', $request->sourceIds)
                    ->where('user_id', auth()->id())
                    ->where('status', 'active')
                    ->get();

                if (!$source || $source->count() == 0) {
                    return $this->errorResponse('Item tidak ditemukan', 200);
                }

                foreach ($source as $item) {
                    $item->appendToNode($target);
                    $item->save();
                }
            } elseif ($request->targetId == 0) {
                $source = Data::whereIn('slug', $request->sourceIds)
                    ->where('user_id', auth()->id())
                    ->where('status', 'active')
                    ->get();

                if (!$source || $source->count() == 0) {
                    return $this->errorResponse('Item tidak ditemukan', 200);
                }

                foreach ($source as $item) {
                    $item->saveAsRoot();
                }
            }

            $this->_logActivity('Memindahkan ' . $source->count() . ' berkas', 'move-item');

            DB::commit();
            return $this->successResponse($source, 'Item berhasil dipindahkan!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function softDelete(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'ids' => 'required|array',
        ], [], [
            'ids' => 'Item',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            $data = Data::whereIn('slug', $request->ids)
                ->where('status', 'active')
                ->get();
            $dataCount = 0;

            if ($data->count() == 0) {
                return $this->errorResponse('Item tidak ditemukan', 200);
            }

            foreach ($data as $item) {
                if ($item->type == 'file') {
                    $item->delete();
                    $dataCount++;
                }

                if ($item->type == 'folder') {
                    $descendants = $item->descendants()->get();
                    $descendants->each(function ($child) {
                        $child->delete();
                    });
                    $item->delete();
                    $dataCount++;
                }
            }

            $this->_logActivity('Menghapus ' . $dataCount . ' berkas', 'delete-item', 'web', User::find(auth()->id()));

            DB::commit();
            return $this->successResponse($dataCount . ' Data', 'Item berhasil dihapus!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function restore(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $validate = Validator::make($request->all(), [
            'ids' => 'required|array',
        ], [], [
            'ids' => 'Item',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            $data = Data::whereIn('slug', $request->ids)
                ->where('user_id', auth()->id())
                ->onlyTrashed()
                ->get();
            if ($data->count() == 0) {
                return $this->errorResponse('Data not found', 200);
            }

            foreach ($data as $dt) {
                $dt->restore();
                $dt->saveAsRoot();
            }

            $message = $data->count() > 1
                ? 'Mengembalikan ' . $data->count() . ' item dari tempat sampah'
                : 'Mengembalikan Berkas dari tempat sampah';

            $this->_logActivity($message, 'restore-item', 'web', User::find(auth()->id()));

            DB::commit();
            return $this->successResponse(null, 'Item berhasil dikembalikan!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function forceDelete(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'ids' => 'required|array',
        ], [], [
            'ids' => 'Item',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            $data = Data::whereIn('slug', $request->ids)
                ->where('user_id', auth()->id())
                ->onlyTrashed()
                ->get();

            if ($data->count() == 0) {
                return $this->errorResponse('Item tidak ditemukan', 200);
            }

            foreach ($data as $item) {
                if ($item->type == 'file') {
                    if ($item->temp_path && file_exists($item->temp_path)) {
                        File::delete($item->temp_path);
                    }
                    $item->forceDelete();
                }

                if ($item->type == 'folder') {
                    $descendants = $item->descendants()->get();
                    if ($descendants->count() > 0) {
                        $descendants->each(function ($child) {
                            $child->forceDelete();
                        });
                    }
                    $item->forceDelete();
                }
            }

            $this->_logActivity('Menghapus permanen ' . $data->count() . ' berkas', 'force-delete-item', 'web', User::find(auth()->id()));

            DB::commit();
            return $this->successResponse($data->count() . ' Data', 'Item berhasil dihapus permanen!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function setFavorite(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'ids' => 'required|array',
            'status' => 'nullable|boolean',
        ], [], [
            'ids' => 'Items',
            'status' => 'Status',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $datas = Data::whereIn('slug', $request->ids)
            ->where('status', 'active')
            ->get();

        if ($datas->isEmpty()) {
            return $this->errorResponse('Item tidak ditemukan', 200);
        }

        DB::beginTransaction();
        try {
            foreach ($datas as $data) {
                $data->favorite = $data->favorite == false ? true : false;
                $data->save();

                if (auth()->check()) {
                    $message = ($data->favorite == true ? 'Menambahkan' : 'Menghapus') . ' favorit pada ' . $data->type . ' ' . $data->name;
                    $this->_logActivity($message, 'favorite-' . $data->type, 'web', $data);
                }
            }
            DB::commit();
            return $this->successResponse($datas, 'Status favorit berhasil diubah!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getFavoriteItems(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        try {
            $data = Data::where('user_id', auth()->id())
                ->where('status', 'active')
                ->where('favorite', true)
                ->orderBy('type', 'desc')
                ->orderBy('name')
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getFavoriteItemsV2(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        try {
            $data = Data::where('user_id', auth()->id())
                ->where('status', 'active')
                ->where('favorite', true)
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
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getItemsTrashed(Request $request)
    {
        try {
            $checkUserAccess = auth()->user()->access;
            if ($checkUserAccess == 'false') {
                return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
            }
            $data = Data::where('user_id', auth()->id())
                ->where('type', 'file')
                ->orderBy('type', 'desc')
                ->orderBy('name')
                ->onlyTrashed()
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function getItemsTrashedV2(Request $request)
    {
        try {
            $checkUserAccess = auth()->user()->access;
            if ($checkUserAccess == 'false') {
                return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
            }
            $data = Data::where('user_id', auth()->id())
                ->where('type', 'file')
                ->orderBy('type', 'desc')
                ->orderBy('name')
                ->onlyTrashed()
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

    function postDownload(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'ids' => 'required|array',
        ], [], [
            'ids' => 'Items',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $datas = Data::whereIn('slug', $request->ids)
            ->where('status', 'active')
            ->where('type', 'file')
            ->get();

        if ($datas->isEmpty()) {
            return $this->errorResponse('Item tidak ditemukan', 200);
        }

        $returns = [];

        foreach ($datas as $data) {
            if ($data->path) {
                $url = 'https://drive.google.com/uc?id=' . $data->path . '&export=download';
                if (auth()->check()) {
                    $this->_logActivity('Mengunduh file ' . $data->type . ' ' . $data->name, 'download-' . $data->type, 'web', $data);
                }
                $returns[] = [
                    'url' => $url,
                    'name' => $data->name . '.' . $data->extension,
                    'size' => $data->size,
                    'type' => $data->mimes,
                ];
            } elseif (!$data->path && $data->temp_path) {
                $url = $data->temp_path;
                $url = str()->after($url, 'public/storage/temp/');
                $url = asset('storage/temp/' . $url);

                if (auth()->check()) {
                    $this->_logActivity('Mengunduh ' . $data->type . ' ' . $data->name, 'download-' . $data->type, 'web', $data);
                }
                $returns[] = [
                    'url' => $url,
                    'name' => $data->name . '.' . $data->extension,
                    'size' => $data->size,
                    'type' => $data->mimes,
                ];
            } else {
                $returns[] = [
                    'url' => null,
                    'name' => $data->name . '.' . $data->extension,
                    'size' => $data->size,
                    'type' => $data->mimes,
                ];
            }
        }
        return $this->successResponse($returns, 'Download file', 200);
    }
}
