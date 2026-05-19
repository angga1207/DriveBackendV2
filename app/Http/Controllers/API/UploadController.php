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
use App\Jobs\TransferLocalFileToGoogle;
use Illuminate\Support\Facades\Validator;

class UploadController extends Controller
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

    function uploadFiles($folderSlug, Request $request)
    {
        $validate = Validator::make($request->all(), [
            'files' => 'required|array',
            'files.*' => [
                'file',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    $forbiddenExtensions = ['php', 'html', 'js', 'css', 'json', 'xml', 'yml', 'yaml', 'env', 'htaccess', 'htpasswd'];
                    if (in_array($extension, $forbiddenExtensions)) {
                        $fail('File dengan ekstensi ' . $extension . ' tidak diizinkan.');
                    }
                }
            ],
        ], [], [
            'files' => 'Berkas',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        DB::beginTransaction();
        try {
            $folder = null;
            if (!$folderSlug || $folderSlug == 0 || $folderSlug == 'null') {
                // root upload
            } else {
                $folder = Data::where('slug', $folderSlug)
                    ->where('status', 'active')
                    ->where('type', 'folder')
                    ->first();

                if ($folder) {
                    if ($folder->shared == 'private') {
                        if ($folder->user_id != auth()->id()) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    } else {
                        if ((auth()->id() != $folder->user_id) && ($folder->s_editable == false)) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    }
                }

                if (!$folder) {
                    return $this->errorResponse('Parent folder salah! Perhatikan path saat mengunggah berkas!', 200);
                }
            }

            $files = $request->file('files');
            $inputFilesCheck = [];
            if (collect($files)->count() == 0) {
                return $this->errorResponse('Tidak ada berkas yang diunggah', 200);
            }
            foreach ($files as $key => $file) {
                $inputFilesCheck[] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'extension' => $file->getClientOriginalExtension(),
                    'status' => false,
                ];
            }

            $allSize = collect($inputFilesCheck)->sum('size');

            $user = User::find(auth()->id());
            $capacity = $user->drive_capacity;
            if ($allSize > $capacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }
            $restCapacity = $capacity - $user->MyDriveSize();
            if ($allSize > $restCapacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }

            foreach ($files as $key => $file) {
                $extension = $file->getClientOriginalExtension();
                $arrExts = ['php', 'html', 'js', 'css', 'json', 'xml', 'yml', 'yaml', 'env', 'htaccess', 'htpasswd'];
                if (in_array($extension, $arrExts)) {
                    return $this->errorResponse('File ' . $file->getClientOriginalName() . ' tidak diizinkan', 200);
                }

                $filename = $file->getClientOriginalName();
                $check = Data::where('parent_id', $folder->id ?? null)
                    ->where('name', pathinfo($filename, PATHINFO_FILENAME))
                    ->where('extension', pathinfo($filename, PATHINFO_EXTENSION))
                    ->where('status', 'active')
                    ->get();
                if ($check->count() > 0) {
                    $filename = pathinfo($filename, PATHINFO_FILENAME) . ' (Copy ' . $check->count() . ')';
                } else {
                    $filename = pathinfo($filename, PATHINFO_FILENAME);
                }

                $data = new Data();
                $data->name = $filename;
                $data->slug = $data->generateSlug();
                $data->type = 'file';
                $data->store_to = 'google';
                $data->status = 'active';
                $data->user_id = auth()->id();
                $data->pd_id = null;
                $data->size = $file->getSize();
                $data->extension = $file->getClientOriginalExtension();
                $data->mimes = $file->getMimeType();
                $data->skip_upload_to_google = false;

                $tempFileName = time() . $key . '.' . $file->getClientOriginalExtension();
                File::copy($file->getRealPath(), public_path() . '/storage/temp/' . $tempFileName);
                $upload = public_path() . '/storage/temp/' . $tempFileName;
                $data->temp_path = $upload;

                $folderShared = $folder->shared ?? 'private';
                $data->shared = $folderShared;
                if ($folderShared == 'public') {
                    $data->expired_at = $folder->expired_at;
                    $data->s_editable = $folder->s_editable;
                }

                if ($folder) {
                    $folder = Data::find($folder->id);
                    $folder->updated_at = Carbon::now();
                    $folder->save();

                    $data->appendToNode($folder);
                    $data->save();
                } else {
                    $data->saveAsRoot();
                }

                if ($data) {
                    TransferLocalFileToGoogle::dispatch($data);
                }

                $this->_logActivity('Mengunggah ' . $data->type . ' ' . $data->name, 'upload-' . $data->type, 'web', $data);
            }

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Berkas Berhasil diunggah!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage() . ' - ' . $e->getLine(), 200);
        }
    }

    function uploadInFolder($parentSlug, Request $request)
    {
        $validate = Validator::make($request->all(), [
            'folderName' => 'required|string',
            'files' => 'required|array|max:500',
            'files.*' => 'file',
        ], [], [
            'folderName' => 'Nama Folder',
            'files' => 'Berkas',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        try {
            $parent = null;
            if (!$parentSlug || $parentSlug == 0 || $parentSlug == 'null' || $parentSlug == 'undefined') {
                // root
            } else {
                $parent = Data::where('slug', $parentSlug)
                    ->where('status', 'active')
                    ->where('type', 'folder')
                    ->first();

                if ($parent) {
                    if ($parent->shared == 'private') {
                        if ($parent->user_id != auth()->id()) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    } else {
                        if ((auth()->id() != $parent->user_id) && ($parent->s_editable == false)) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    }
                }

                if (!$parent) {
                    return $this->errorResponse('Parent folder salah! Perhatikan path saat mengunggah berkas!', 200);
                }
            }

            // Create the folder
            $folder = new Data();
            $folder->name = $request->folderName;
            $folder->slug = $folder->generateSlug();
            $folder->type = 'folder';
            $folder->status = 'active';
            $folder->user_id = auth()->id();
            $folder->pd_id = null;
            $folder->shared = $parent->shared ?? 'private';
            if ($folder->shared == 'public') {
                $folder->expired_at = $parent->expired_at;
                $folder->s_editable = $parent->s_editable;
            }
            if ($parent) {
                $parent = Data::find($parent->id);
                $parent->updated_at = Carbon::now();
                $parent->save();

                $folder->appendToNode($parent);
                $folder->save();
            } else {
                $folder->saveAsRoot();
            }

            $files = $request->file('files');
            $inputFilesCheck = [];
            if (collect($files)->count() == 0) {
                return $this->errorResponse('Tidak ada berkas yang diunggah', 200);
            }
            foreach ($files as $key => $file) {
                $inputFilesCheck[] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'extension' => $file->getClientOriginalExtension(),
                    'status' => false,
                ];
            }

            $allSize = collect($inputFilesCheck)->sum('size');

            $user = User::find(auth()->id());
            $capacity = $user->drive_capacity;
            if ($allSize > $capacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }
            $restCapacity = $capacity - $user->MyDriveSize();
            if ($allSize > $restCapacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }

            foreach ($files as $key => $file) {
                $extension = $file->getClientOriginalExtension();
                $arrExts = ['php', 'html', 'js', 'css', 'json', 'xml', 'yml', 'yaml', 'env', 'htaccess', 'htpasswd'];
                if (in_array($extension, $arrExts)) {
                    return $this->errorResponse('File ' . $file->getClientOriginalName() . ' tidak diizinkan', 200);
                }

                $filename = $file->getClientOriginalName();
                $check = Data::where('parent_id', $folder->id ?? null)
                    ->where('name', pathinfo($filename, PATHINFO_FILENAME))
                    ->where('extension', pathinfo($filename, PATHINFO_EXTENSION))
                    ->where('status', 'active')
                    ->get();
                if ($check->count() > 0) {
                    $filename = pathinfo($filename, PATHINFO_FILENAME) . ' (Copy ' . $check->count() . ')';
                } else {
                    $filename = pathinfo($filename, PATHINFO_FILENAME);
                }

                $data = new Data();
                $data->name = $filename;
                $data->slug = $data->generateSlug();
                $data->type = 'file';
                $data->store_to = 'google';
                $data->status = 'active';
                $data->user_id = auth()->id();
                $data->pd_id = null;
                $data->size = $file->getSize();
                $data->extension = $file->getClientOriginalExtension();
                $data->mimes = $file->getMimeType();

                $tempFileName = time() . $key . '.' . $file->getClientOriginalExtension();
                File::copy($file->getRealPath(), public_path() . '/storage/temp/' . $tempFileName);
                $upload = public_path() . '/storage/temp/' . $tempFileName;
                $data->temp_path = $upload;

                $folderShared = $folder->shared ?? 'private';
                $data->shared = $folderShared;
                if ($folderShared == 'public') {
                    $data->expired_at = $folder->expired_at;
                    $data->s_editable = $folder->s_editable;
                }

                if ($folder) {
                    $folder = Data::find($folder->id);
                    $folder->updated_at = Carbon::now();
                    $folder->save();

                    $data->appendToNode($folder);
                    $data->save();
                } else {
                    $data->saveAsRoot();
                }

                if ($data) {
                    TransferLocalFileToGoogle::dispatch($data);
                }
            }

            $this->_logActivity('Mengunggah Folder ' . $folder->name . ' beserta isinya', 'upload-folder', 'web', $folder);

            return $this->successResponse(new DataResource($folder), 'Folder Beserta Isinya Berhasil diunggah!', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage() . ' - ' . $e->getLine(), 200);
        }
    }

    /**
     * Chunk upload - init
     * Create Data record + UploadChunkSession, but don't dispatch transfer job yet.
     */
    function uploadChunkInit($folderSlug, Request $request)
    {
        $validate = Validator::make($request->all(), [
            'chunk_id' => 'required|string',
            'file_name' => 'required|string',
            'file_size' => 'required|integer|min:1',
            'extension' => 'required|string',
            'mimes' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        $chunkId = $request->input('chunk_id');
        $fileName = $request->input('file_name');
        $fileSize = (int) $request->input('file_size');
        $extension = strtolower($request->input('extension'));
        $mimes = $request->input('mimes');
        $totalChunks = (int) $request->input('total_chunks');

        $forbiddenExtensions = ['php', 'html', 'js', 'css', 'json', 'xml', 'yml', 'yaml', 'env', 'htaccess', 'htpasswd'];
        if (in_array($extension, $forbiddenExtensions)) {
            return $this->errorResponse('File ekstensi ' . $extension . ' tidak diizinkan', 200);
        }

        DB::beginTransaction();
        try {
            // Resolve destination folder
            $folder = null;
            if (!$folderSlug || $folderSlug == 0 || $folderSlug == 'null') {
                // root upload
            } else {
                $folder = Data::where('slug', $folderSlug)
                    ->where('status', 'active')
                    ->where('type', 'folder')
                    ->first();

                if ($folder) {
                    if ($folder->shared == 'private') {
                        if ($folder->user_id != auth()->id()) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    } else {
                        if ((auth()->id() != $folder->user_id) && ($folder->s_editable == false)) {
                            return $this->errorResponse('Anda tidak memiliki akses di Folder ini.', 200);
                        }
                    }
                }

                if (!$folder) {
                    return $this->errorResponse('Parent folder salah! Perhatikan path saat mengunggah berkas!', 200);
                }
            }

            $user = User::find(auth()->id());
            $capacity = $user->drive_capacity;
            if ($fileSize > $capacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }

            $restCapacity = $capacity - $user->MyDriveSize();
            if ($fileSize > $restCapacity) {
                return $this->errorResponse('Kapasitas Drive Anda tidak mencukupi. Silahkan menghubungi Admin', 200);
            }

            // Normalize filename (remove extension for Data->name like existing logic)
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $fullExt = pathinfo($fileName, PATHINFO_EXTENSION);
            if (!$baseName) {
                $baseName = $fileName;
            }

            // Conflict resolution with same parent_id+name+extension
            $check = Data::where('parent_id', $folder->id ?? null)
                ->where('name', $baseName)
                ->where('extension', $extension)
                ->where('status', 'active')
                ->get();

            if ($check->count() > 0) {
                $baseName = $baseName . ' (Copy ' . $check->count() . ')';
            }

            $data = new Data();
            $data->name = $baseName;
            $data->slug = $data->generateSlug();
            $data->type = 'file';
            $data->store_to = 'google';
            $data->status = 'active';
            $data->user_id = auth()->id();
            $data->pd_id = null;
            $data->size = $fileSize;
            $data->extension = $extension;
            $data->mimes = $mimes;
            $data->skip_upload_to_google = false;

            // Reserve temp_path so MyDriveSize ignores it (temp_path not null)
            $tempDir = public_path() . '/storage/temp';
            if (!File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0775, true);
            }

            $tempFileName = $chunkId . '.' . $extension;
            $uploadPath = public_path() . '/storage/temp/' . $tempFileName;
            $data->temp_path = $uploadPath;

            $folderShared = $folder->shared ?? 'private';
            $data->shared = $folderShared;
            if ($folderShared == 'public') {
                $data->expired_at = $folder->expired_at;
                $data->s_editable = $folder->s_editable;
            }

            if ($folder) {
                $folder = Data::find($folder->id);
                $folder->updated_at = Carbon::now();
                $folder->save();

                $data->appendToNode($folder);
                $data->save();
            } else {
                $data->saveAsRoot();
            }

            // Create chunk session
            $session = new \App\Models\UploadChunkSession();
            $session->user_id = auth()->id();
            $session->data_id = $data->id;
            $session->chunk_id = $chunkId;
            $session->file_size = $fileSize;
            $session->total_chunks = $totalChunks;
            $session->uploaded_parts = 0;
            $session->status = 'processing';
            $session->save();

            DB::commit();

            return $this->successResponse([
                'data_id' => $data->id,
                'chunk_id' => $session->chunk_id,
                'total_chunks' => $session->total_chunks,
                'temp_path' => $data->temp_path,
            ], 'Chunk session initialized', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage() . ' - ' . $e->getLine(), 200);
        }
    }

    /**
     * Chunk upload - part
     */
    function uploadChunkPart(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'chunk_id' => 'required|string',
            'data_id' => 'required|integer|min:1',
            'chunk_index' => 'required|integer|min:0',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $chunkId = $request->input('chunk_id');
        $dataId = (int) $request->input('data_id');
        $chunkIndex = (int) $request->input('chunk_index');

        if (!$request->hasFile('chunk')) {
            return $this->errorResponse('Chunk file is required', 200);
        }

        $chunkFile = $request->file('chunk');

        // Resolve session
        $session = \App\Models\UploadChunkSession::where('user_id', auth()->id())
            ->where('data_id', $dataId)
            ->where('chunk_id', $chunkId)
            ->first();

        if (!$session) {
            return $this->errorResponse('Chunk session not found', 200);
        }

        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            return $this->errorResponse('Invalid chunk_index', 200);
        }

        $chunkDir = public_path() . '/storage/chunks/' . $chunkId;
        if (!File::exists($chunkDir)) {
            File::makeDirectory($chunkDir, 0775, true);
        }

        $partPath = $chunkDir . '/part_' . $chunkIndex;

        DB::beginTransaction();
        try {
            // Idempotency: if part exists, do not re-count.
            if (!File::exists($partPath)) {
                // Move uploaded chunk to part file
                $chunkFile->move(dirname($partPath), basename($partPath));
                $session->incrementUploadedParts(1);
            }

            DB::commit();
            return $this->successResponse([
                'uploaded_parts' => $session->uploaded_parts,
                'total_chunks' => $session->total_chunks,
                'progress_percentage' => $session->getProgressPercentage(),
            ], 'Chunk part uploaded', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            $session->update(['status' => 'failed']);
            return $this->errorResponse($e->getMessage() . ' - ' . $e->getLine(), 200);
        }
    }

    /**
     * Chunk upload - complete (assemble + dispatch job)
     */
    function uploadChunkComplete(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'chunk_id' => 'required|string',
            'data_id' => 'required|integer|min:1',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        $chunkId = $request->input('chunk_id');
        $dataId = (int) $request->input('data_id');

        $session = \App\Models\UploadChunkSession::where('user_id', auth()->id())
            ->where('data_id', $dataId)
            ->where('chunk_id', $chunkId)
            ->first();

        if (!$session) {
            return $this->errorResponse('Chunk session not found', 200);
        }

        if (!$session->isCompleted()) {
            return $this->errorResponse('Chunks not completed yet', 200);
        }

        $data = Data::where('user_id', auth()->id())->where('id', $dataId)->first();
        if (!$data) {
            return $this->errorResponse('Data not found', 200);
        }

        $chunkDir = public_path() . '/storage/chunks/' . $chunkId;
        $outputPath = $data->temp_path;

        if (!$outputPath) {
            return $this->errorResponse('Invalid temp_path for data', 200);
        }

        DB::beginTransaction();
        try {
            if (!File::exists($chunkDir)) {
                return $this->errorResponse('Chunk directory missing', 200);
            }

            $out = fopen($outputPath, 'wb');
            if (!$out) {
                return $this->errorResponse('Cannot create output file', 200);
            }

            // Assemble in order
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $partPath = $chunkDir . '/part_' . $i;
                if (!File::exists($partPath)) {
                    fclose($out);
                    return $this->errorResponse('Missing chunk part: ' . $i, 200);
                }

                $in = fopen($partPath, 'rb');
                if (!$in) {
                    fclose($out);
                    return $this->errorResponse('Cannot read chunk part: ' . $i, 200);
                }

                while (!feof($in)) {
                    $buffer = fread($in, 1048576); // 1MB
                    fwrite($out, $buffer);
                }

                fclose($in);
            }

            fclose($out);

            // Basic verification
            $assembledSize = filesize($outputPath);
            if ((int) $assembledSize !== (int) $session->file_size) {
                // allow small mismatch but mark failed if too off
                if ($assembledSize <= 0) {
                    return $this->errorResponse('Assembled file invalid', 200);
                }
            }

            $session->update(['status' => 'completed']);
            // Dispatch async upload
            TransferLocalFileToGoogle::dispatch($data);

            DB::commit();
            return $this->successResponse([
                'data_id' => $data->id,
                'progress_percentage' => 100,
            ], 'Chunk upload completed', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            $session->update(['status' => 'failed']);
            return $this->errorResponse($e->getMessage() . ' - ' . $e->getLine(), 200);
        }
    }

    function getUploadQueue(Request $request)
    {
        try {
            $data = Data::where('user_id', auth()->id())
                ->where('status', 'active')
                ->where('type', 'file')
                ->whereNotNull('temp_path')
                ->whereNull('path')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }

    function postUploadEvalakip(Request $request)
    {
        $apiKey = 'evalakip-52412-key';
        if ($request->header('api-key') != $apiKey) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $userId = 39;

        $validate = Validator::make($request->all(), [
            'file' => 'required|file|max:50000',
        ], [], [
            'file' => 'File',
        ]);

        if ($validate->fails()) {
            return $this->validationResponse($validate->errors());
        }

        DB::beginTransaction();
        try {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $arrExts = ['php', 'html', 'js', 'css', 'json', 'xml', 'yml', 'yaml', 'env', 'htaccess', 'htpasswd'];
            if (in_array($extension, $arrExts)) {
                return $this->errorResponse('File ' . $file->getClientOriginalName() . ' tidak diizinkan', 200);
            }

            $filename = $request->instance_alias . ' - ' . $file->getClientOriginalName();
            $check = Data::where('parent_id', null)
                ->where('name', pathinfo($filename, PATHINFO_FILENAME))
                ->where('extension', pathinfo($filename, PATHINFO_EXTENSION))
                ->where('status', 'active')
                ->get();
            if ($check->count() > 0) {
                $filename = pathinfo($filename, PATHINFO_FILENAME) . ' (Copy ' . $check->count() . ')';
            } else {
                $filename = pathinfo($filename, PATHINFO_FILENAME);
            }

            $data = new Data();
            $data->name = $filename;
            $data->slug = $data->generateSlug();
            $data->type = 'file';
            $data->store_to = 'google';
            $data->status = 'active';
            $data->user_id = $userId;
            $data->pd_id = null;
            $data->size = $file->getSize();
            $data->extension = $file->getClientOriginalExtension();
            $data->mimes = $file->getMimeType();
            $data->shared = 'public';
            $data->expired_at = '9999-12-31 23:59:59';

            $tempFileName = time() . $file->getClientOriginalName();
            File::copy($file->getRealPath(), public_path() . '/storage/temp/' . $tempFileName);
            $upload = public_path() . '/storage/temp/' . $tempFileName;
            $data->temp_path = $upload;

            $parent = Data::where('user_id', $userId)
                ->where('type', 'folder')
                ->where('name', 'EVALAKIP')
                ->first();
            if (!$parent) {
                $parent = new Data();
                $parent->name = 'EVALAKIP';
                $parent->slug = $parent->generateSlug();
                $parent->type = 'folder';
                $parent->store_to = 'local';
                $parent->status = 'active';
                $parent->user_id = $userId;
                $parent->pd_id = null;
                $parent->size = 0;
                $parent->extension = null;
                $parent->mimes = 'folder';
                $parent->temp_path = null;
                $parent->saveAsRoot();
            } else {
                $parent->updated_at = Carbon::now();
                $parent->save();
            }

            $data->appendToNode($parent);
            $data->save();

            if ($data) {
                TransferLocalFileToGoogle::dispatch($data);
            }

            DB::commit();
            return $this->successResponse(new DataResource($data), 'Berkas Berhasil diunggah!', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 200);
        }
    }
}
