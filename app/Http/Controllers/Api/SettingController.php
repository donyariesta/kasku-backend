<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenantId');
        if (!$tenantId) {
            return response()->json([]);
        }

        return response()->json(Setting::where('tenantId', $tenantId)->get());
    }

    public function show(Request $request, int $fieldId): JsonResponse
    {
        $tenantId = $request->query('tenantId');
        if (!$tenantId) {
            return response()->json(null);
        }

        return response()->json(
            Setting::where('tenantId', $tenantId)->where('fieldId', $fieldId)->first()
        );
    }

    public function upsert(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenantId' => 'required|uuid',
            'fieldId' => 'required|integer',
            'datetimeValue' => 'nullable|date',
            'dateValue' => 'nullable|date',
            'stringValue' => 'nullable|string',
            'booleanValue' => 'nullable|boolean',
            'numberValue' => 'nullable|integer',
            'jsonValue' => 'nullable',
            'blobValue' => 'nullable|string',
        ]);

        $setting = Setting::updateOrCreate(
            ['tenantId' => $payload['tenantId'], 'fieldId' => $payload['fieldId']],
            $payload
        );

        return response()->json($setting);
    }
}
