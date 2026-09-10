<?php

namespace App\Traits;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait AuthFailureResponses
{
    protected function authFailure(string $code, int $status = 200, array $errors = [], ?string $reference = null): JsonResponse
    {
        $messages = [
            'ACCOUNT_DELETED' => 'Akun ini telah dihapus dan tidak memiliki izin akses. Login dan pendaftaran ulang tidak dapat dilanjutkan. Hubungi administrator Drive OI untuk meminta pemulihan akun dan izin akses.',
            'ACCOUNT_CONFLICT' => 'Email atau username ini sudah terhubung ke akun Drive OI lain. Gunakan metode login akun tersebut atau hubungi administrator untuk bantuan.',
            'ACCOUNT_NOT_FOUND' => 'Akun Drive OI belum terdaftar. Daftar melalui Google, masuk dengan akun Semesta, atau hubungi administrator untuk membuat akun.',
            'INVALID_CREDENTIALS' => 'Username/NIP atau kata sandi tidak cocok. Periksa kembali data Anda dan gunakan metode login yang sesuai.',
            'LOGIN_BLOCKED' => 'Login diblokir karena terlalu banyak percobaan gagal. Hubungi administrator untuk membuka blokir akun atau jaringan Anda.',
            'VALIDATION_FAILED' => 'Data login atau pendaftaran belum lengkap atau tidak valid. Periksa kembali kolom yang ditandai.',
            'PROVIDER_UNAVAILABLE' => 'Layanan Semesta sedang tidak dapat dihubungi. Coba lagi beberapa saat.',
            'PROVIDER_INVALID_RESPONSE' => 'Data akun dari layanan login belum lengkap. Coba lagi; jika tetap gagal, hubungi administrator layanan tersebut.',
            'AUTH_SERVER_ERROR' => 'Login atau pendaftaran belum dapat diproses karena gangguan server. Coba lagi beberapa saat. Jika tetap gagal, sampaikan kode referensi kepada administrator.',
        ];

        return response()->json(array_filter([
            'status' => 'error',
            'code' => $code,
            'message' => $messages[$code] ?? $messages['AUTH_SERVER_ERROR'],
            'errors' => $errors ?: null,
            'reference' => $reference,
        ], fn ($value) => $value !== null), $status);
    }

    protected function authException(Throwable $exception): JsonResponse
    {
        $reference = (string) Str::uuid();
        // Do not log request bodies, passwords, OAuth codes, or SQL bindings.
        Log::error('Authentication operation failed', [
            'reference' => $reference,
            'exception' => $exception::class,
            'exception_code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        if ($exception instanceof UniqueConstraintViolationException) {
            return $this->authFailure('ACCOUNT_CONFLICT', 409, reference: $reference);
        }

        return $this->authFailure('AUTH_SERVER_ERROR', 500, reference: $reference);
    }
}
