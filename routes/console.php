<?php

use Illuminate\Support\Facades\DB;
use App\Models\Data;
use Illuminate\Support\Facades\Log;


$schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

$schedule->call(function () {
    // Log::info('SCHEDULER : Mengecek antrian job TransferLocalFileToGoogle.');
    Log::info('PHP VERSION : ' . phpversion());

    $currentJobsDataIds = [];
    $currentJobs =
        DB::table('jobs')
        ->where('queue', 'default')
        ->get();
    foreach ($currentJobs as $job) {
        $payload = json_decode($job->payload, true);
        if (isset($payload['data']['command'])) {
            $command = unserialize($payload['data']['command']);
            if ($command instanceof \App\Jobs\TransferLocalFileToGoogle) {
                // $dataId = after s:2:"id";i: extract the integer value until the next semicolon
                preg_match('/s:2:"id";i:(\d+);/', $payload['data']['command'], $matches);
                $dataId = $matches[1] ?? null;
                if ($dataId) {
                    $currentJobsDataIds[] = $dataId;
                }
            }
        }
    }

    $datas = Data::whereNotNull('temp_path')
        ->where('type', 'file')
        // ->where('size', '<', 50000000)
        ->whereNull('deleted_at')
        ->where('skip_upload_to_google', false)
        ->whereNotIn('id', [20859])
        ->whereNotIn('id', $currentJobsDataIds)
        ->oldest('created_at')
        ->withTrashed()
        ->take(30)
        ->get();

    if ($datas->isEmpty()) {
        Log::info('SCHEDULER : Tidak ada data yang perlu diupload.');
        return;
    }

    foreach ($datas as $data) {
        // Cek apakah job TransferLocalFileToGoogle masih ada di queue (belum dieksekusi)
        $jobPending = DB::table('jobs')
            // ->where('payload', 'like', '%TransferLocalFileToGoogle%')
            ->where('queue', 'default')
            ->where('payload', 'like', '%' . 's:2:\"id\";i:' . $data->id . ';' . '%')
            ->exists();

        if ($jobPending) {
            // Log::info('SCHEDULER : Job TransferLocalFileToGoogle masih ada di antrian, skip dispatch.');
            Log::info('SCHEDULER : Job TransferLocalFileToGoogle masih ada di antrian untuk Data ID: ' . $data->id . ', skip dispatch.');
            return;
        }

        Log::info('SCHEDULER : Dispatching job untuk Data ID: ' . $data->id . ' | File: ' . $data->name);

        if (\App\Jobs\TransferLocalFileToGoogle::dispatch($data)->onQueue('default')) {
            Log::info('SCHEDULER : Job berhasil di-dispatch untuk Data ID: ' . $data->id);
        } else {
            Log::error('SCHEDULER : Gagal dispatch job untuk Data ID: ' . $data->id);
        }
    }
})->everyMinute();



$schedule->call(function () {
    $datas = Data::whereNotNull('temp_path')
        ->where('type', 'file')
        // ->where('size', '<', 50000000)
        ->whereNull('deleted_at')
        ->where('skip_upload_to_google', true)
        // JANGAN reset placeholder upload-chunk yang masih aktif
        // (kalau tidak, TransferLocalFileToGoogle bisa forceDelete Data saat part masih belum selesai)
        ->whereNotIn('id', function ($q) {
            $q->select('data_id')
                ->from('upload_chunk_sessions')
                ->whereIn('status', ['pending', 'processing']);
        })
        ->oldest('created_at')
        ->withTrashed()
        ->get();

    if ($datas->isEmpty()) {
        Log::info('SCHEDULER : Tidak ada data yang perlu diupload (skip_upload_to_google = true).');
        return;
    }

    foreach ($datas as $data) {
        $data->update(['skip_upload_to_google' => false]);
        // Log::info('SCHEDULER : Reset skip_upload_to_google untuk Data ID: ' . $data->id);
    }

    Log::info('SCHEDULER : Reset skip_upload_to_google untuk ' . $datas->count() . ' data.');
})->hourly();
