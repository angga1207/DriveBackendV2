<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Data;
use Illuminate\Http\File;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\File as FacadesFile;

class TransferLocalFileToGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(private Data $data)
    {
        $this->data = $data;
    }

    public function handle()
    {
        if (!$this->data) {
            Log::warning('JOB : Data tidak ditemukan, job akan dihentikan.');
            return;
        }

        $data = $this->data->fresh();

        Log::info('JOB : Memproses Data ID: ' . $data->id . ' | File: ' . $data->name);

        $filePath = $data->temp_path;
        // Simpan path lokal untuk keperluan penghapusan setelah upload Google Drive berhasil
        $localTempPath = $filePath;

        if (!$filePath) {
            Log::warning('JOB : File tidak ditemukan di lokal, data akan diskip. Path: ' . $filePath . ' | Data ID: ' . $data->id);
            $data->update(['skip_upload_to_google' => true]);
            return;
        }

        if (!file_exists($filePath)) {
            Log::warning('JOB : File tidak ditemukan di lokal, data tidak di-delete. Set skip_upload_to_google=true. Path: ' . $filePath . ' | Data ID: ' . $data->id);

            // Jangan forceDelete karena ini bisa bikin job lain (atau upload chunk) kehilangan record Data (FK cascade).
            // Worker nanti bisa retry job/flow yang sama setelah file tersedia.
            $data->update(['skip_upload_to_google' => true]);

            return;
        }

        DB::beginTransaction();
        try {
            $gdFolder = $data->user_id . '/' . Carbon::now()->format('Ymd');
            $fileName = $this->sanitizeFileName($data->name) . '.' . $data->extension;
            $gdPath = 'files_2/' . $gdFolder;

            Log::info('JOB : Mengupload ke Google Drive. DataID : ' . $data->id . ' | Folder: ' . $gdPath . ' | File: ' . $fileName . ' | File Size: ' . $data->isoSize($data->size));

            // PDF dan gambar: biarkan Google generate nama file sendiri agar preview tidak error
            $mimePrefix = strtok($data->mimes ?? '', '/');
            $useRandomName = in_array($data->extension, ['pdf']) || in_array($mimePrefix, ['image']);

            if ($useRandomName) {
                $googleUpload = Storage::disk('google')->putFile($gdPath, new File($filePath), 'public');
            } else {
                $googleUpload = Storage::disk('google')->putFileAs($gdPath, new File($filePath), $fileName, 'public');
            }

            $disk = Storage::disk('google');

            // Ambil metadata langsung untuk mendapatkan Google Drive file id,
            // tanpa memanggil listContents().
            $meta = $disk->getMetadata($googleUpload);

            // Laravel biasanya mengembalikan array, tapi kita buat defensif.
            $extraMetadata = [];
            if (is_array($meta) && isset($meta['extraMetadata']) && is_array($meta['extraMetadata'])) {
                $extraMetadata = $meta['extraMetadata'];
            } elseif (is_array($meta) && isset($meta['extra_metadata']) && is_array($meta['extra_metadata'])) {
                // beberapa adapter bisa memakai key berbeda
                $extraMetadata = $meta['extra_metadata'];
            } elseif (is_object($meta) && method_exists($meta, 'extraMetadata')) {
                $extraMetadata = $meta->extraMetadata();
            }

            $googleDriveId = $extraMetadata['id'] ?? null;

            if ($googleDriveId) {
                $data->path = $googleDriveId;
                $data->gd_folder = $gdFolder;
                $data->temp_path = null;
                $data->upload_batch_id = null;
                $data->skip_upload_to_google = true;
                $data->saveQuietly();

                DB::commit();

                // Hapus file lokal HANYA jika upload ke Google Drive sukses
                if ($data->path && $localTempPath && file_exists($localTempPath)) {
                    try {
                        FacadesFile::delete($localTempPath);
                    } catch (\Throwable $e) {
                        // Jangan ubah status DB karena ini sukses upload; cukup log bila gagal delete
                        Log::warning('JOB : Upload berhasil tapi gagal menghapus file lokal. Data ID: ' . $data->id . ' | TempPath: ' . $localTempPath . ' | Error: ' . $e->getMessage());
                    }
                }

                Log::info('JOB : Upload berhasil. Data ID: ' . $data->id . ' | Google Drive ID: ' . $googleDriveId . ' | Slug: ' . $data->slug . ' | Parent Slug: ' . ($data->parent->slug ?? $data->parent_id));
            } else {
                DB::rollBack();
                Log::error('JOB : Upload gagal, metadata Google Drive tidak ditemukan setelah upload. Data ID: ' . $data->id . ' | Path: ' . $googleUpload);
                Log::info('JOB : getMetadata result (debug): ' . json_encode($meta));
            }
        } catch (\Exception $e) {
            DB::rollBack();

            $data->update(['skip_upload_to_google' => true]);

            Log::error('JOB : Exception saat upload. Data ID: ' . $data->id . ' | Error: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
        }
    }

    private function sanitizeFileName(string $name): string
    {
        $replacements = [
            "'" => '_',
            '"' => '_',
            '`' => '_',
            '-' => '_',
            ' ' => '_',
            '&' => 'and',
            ':' => '',
            '|' => '',
            '?' => '',
            '*' => '',
            '/' => '',
            '\\' => '',
            '<' => '',
            '>' => '',
            '^' => '',
            '%' => '',
            '$' => '',
            '#' => '',
            '@' => '',
            '!' => '',
            '+' => '',
            '=' => '',
            '{' => '',
            '}' => '',
            '[' => '',
            ']' => '',
            '(' => '',
            ')' => '',
            ';' => '',
            ',' => '',
            '~' => '',
            '.' => '',
        ];

        $name = str_replace(array_keys($replacements), array_values($replacements), $name);
        $name = preg_replace('/-{2,}/', '-', $name);
        $name = preg_replace('/_{2,}/', '_', $name);
        $name = trim($name, ' -_');

        return $name;
    }
}
