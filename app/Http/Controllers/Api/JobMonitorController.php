<?php

namespace App\Http\Controllers\Api;

use App\Services\JobMonitorService;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobMonitorController extends BaseApiController
{
    public function __construct(private readonly JobMonitorService $service)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        return response()->json($this->service->overview());
    }

    public function runs(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        $limit = min((int) $request->query('limit', 50), 100);

        return response()->json($this->service->listRuns($limit));
    }

    public function run(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        $payload = $request->validate([
            'jobKey' => 'required|string',
            'tenantId' => 'nullable|uuid',
        ]);

        try {
            $run = $this->service->dispatch(
                $payload['jobKey'],
                $request->user()->id,
                $payload['tenantId'] ?? null
            );
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Unknown job'], 404);
        }

        return response()->json($run, 202);
    }
}
