<?php

namespace App\Http\Controllers\API;

use App\Models\Data;
use App\Traits\JsonReturner;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataResource;

class SearchController extends Controller
{
    use JsonReturner;

    function search(Request $request)
    {
        $checkUserAccess = auth()->user()->access;
        if ($checkUserAccess == 'false') {
            return $this->errorResponse('Anda tidak memiliki akses. Silahkan Menghubungi Admin', 200);
        }

        try {
            if ($request->search == null) {
                return $this->errorResponse('Search query is required', 200);
            }

            $data = Data::search($request->search)
                ->where('user_id', auth()->id())
                ->where('status', 'active')
                ->orderBy('type', 'desc')
                ->orderBy('name')
                ->get();

            return $this->successResponse(DataResource::collection($data), 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 200);
        }
    }
}
