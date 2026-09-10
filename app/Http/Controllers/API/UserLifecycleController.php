<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Data;
use App\Models\SharedData;
use App\Models\UploadBatch;
use App\Models\UploadChunkSession;
use App\Models\User;
use App\Notifications\FirebaseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserLifecycleController extends Controller
{
    public function bulk(Request $request): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', Rule::in(['delete', 'restore', 'force_delete', 'grant_access', 'revoke_access'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $input['ids']);
        $action = $input['action'];
        if (in_array($action, ['delete', 'force_delete', 'revoke_access'], true) && in_array((int) $request->user()->id, $ids, true)) {
            return response()->json(['status' => 'error', 'message' => 'Akun yang sedang Anda gunakan tidak dapat dihapus atau dicabut aksesnya. Batalkan pilihan akun Anda.'], 422);
        }

        try {
            DB::transaction(function () use ($ids, $action, $request) {
                $users = User::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($users->count() !== count($ids)) {
                    throw ValidationException::withMessages(['ids' => ['Sebagian akun tidak ditemukan. Muat ulang daftar dan pilih kembali.']]);
                }
                $requiresDeleted = in_array($action, ['restore', 'force_delete'], true);
                foreach ($users as $user) {
                    if ($user->trashed() !== $requiresDeleted) {
                        throw ValidationException::withMessages(['ids' => ['Status sebagian akun sudah berubah. Muat ulang daftar dan pilih kembali.']]);
                    }
                }
                foreach ($users as $user) {
                    if (in_array($action, ['delete', 'restore', 'force_delete', 'revoke_access'], true)) {
                        $user->tokens()->delete();
                    }
                    if ($action === 'delete') {
                        $user->delete();
                    } elseif ($action === 'restore') {
                        $user->restore();
                    } elseif ($action === 'force_delete') {
                        // Explicit cleanup also supports installations without cascading foreign keys.
                        $ownedData = Data::withTrashed()->where('user_id', $user->id)->select('id');
                        SharedData::where('user_id', $user->id)->orWhereIn('data_id', $ownedData)->delete();
                        UploadChunkSession::where('user_id', $user->id)->orWhereIn('data_id', $ownedData)->delete();
                        Data::withTrashed()->where('user_id', $user->id)->forceDelete();
                        UploadBatch::where('user_id', $user->id)->delete();
                        $user->forceDelete();
                    } else {
                        $user->access = $action === 'grant_access' ? 'true' : 'false';
                        $user->save();
                    }
                }
                activity()->causedBy($request->user())->event('admin-users-'.$action)
                    ->withProperties(['user_ids' => $ids, 'count' => count($ids)])
                    ->log('Admin menjalankan aksi '.$action.' pada '.count($ids).' akun');
            });
        } catch (ValidationException $exception) {
            return response()->json(['status' => 'error', 'message' => collect($exception->errors())->flatten()->first(), 'errors' => $exception->errors()], 422);
        } catch (\Throwable $exception) {
            $reference = (string) Str::uuid();
            Log::error('User management action failed', ['reference' => $reference, 'action' => $action, 'exception' => $exception::class]);

            return response()->json(['status' => 'error', 'message' => 'Aksi gagal disimpan. Tidak ada akun yang diubah. Coba lagi. Kode referensi: '.$reference], 500);
        }

        $notificationFailures = 0;
        if (in_array($action, ['grant_access', 'revoke_access'], true)) {
            foreach (User::whereIn('id', $ids)->get() as $user) {
                try {
                    Notification::send($user, new FirebaseNotification(
                        [$user->id],
                        $action === 'grant_access' ? 'Akses Diberikan' : 'Akses Dicabut',
                        $action === 'grant_access'
                            ? 'Akses pada akun Anda telah diberikan. Silahkan login untuk mengakses layanan.'
                            : 'Akses pada akun Anda telah dicabut. Silahkan hubungi admin untuk informasi lebih lanjut.',
                        null,
                    ));
                } catch (\Throwable $exception) {
                    $notificationFailures++;
                    Log::warning('User access notification failed', ['user_id' => $user->id, 'exception' => $exception::class]);
                }
            }
        }

        $labels = ['delete' => 'dihapus', 'restore' => 'dipulihkan', 'force_delete' => 'dihapus permanen', 'grant_access' => 'diberi akses', 'revoke_access' => 'dicabut aksesnya'];

        return response()->json(['status' => 'success', 'message' => count($ids).' akun berhasil '.$labels[$action].'.'.($notificationFailures ? ' Namun, '.$notificationFailures.' notifikasi belum terkirim.' : ''), 'data' => ['action' => $action, 'affected' => count($ids), 'ids' => $ids]]);
    }
}
