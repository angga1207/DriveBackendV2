<?php

namespace App\Traits;

trait JsonReturner
{
    protected function successResponse($data, $message = null, $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse($message = null, $code = 200)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $code);
    }

    protected function unauthorizedResponse($message = null, $code = 200)
    {
        return response()->json([
            'status' => 'unauthorized',
            'message' => $message,
        ], $code);
    }

    protected function validationResponse($message = null, $code = 200)
    {
        return response()->json([
            'status' => 'error validation',
            'message' => $message,
        ], $code);
    }
}
