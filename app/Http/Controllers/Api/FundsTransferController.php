<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundsTransferController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = FundsTransfer::query()
            ->with(['fromFundsAccount', 'toFundsAccount'])
            ->orderByDesc('date');

        if ($tenantId) {
            $query->where('tenantId', $tenantId);
        }

        return response()->json($query->get());
    }
}
