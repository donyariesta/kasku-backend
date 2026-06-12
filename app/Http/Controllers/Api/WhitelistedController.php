<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\Type;
use App\Models\Whitelisted;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhitelistedController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID is required'], 400);
        }

        $query = Whitelisted::query()
            ->where('tenantId', $tenantId)
            ->with(['member.group', 'type'])
            ->orderByDesc('dateFrom');

        if ($request->filled('memberId')) {
            $query->where('memberId', $request->query('memberId'));
        }

        if ($request->filled('groupId')) {
            $groupId = $request->query('groupId');
            $query->whereHas('member', fn ($q) => $q->where('groupId', $groupId));
        }

        if ($request->filled('year')) {
            $year = (int) $request->query('year');
            if ($request->filled('month')) {
                $month = (int) $request->query('month');
                $periodStart = Carbon::create($year, $month, 1)->startOfDay();
                $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
            } else {
                $periodStart = Carbon::create($year, 1, 1)->startOfDay();
                $periodEnd = Carbon::create($year, 12, 31)->endOfDay();
            }

            $query->where('dateFrom', '<=', $periodEnd->toDateString())
                ->where('dateTo', '>=', $periodStart->toDateString());
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID is required'], 400);
        }

        $payload = $request->validate([
            'memberId' => 'required|uuid',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom',
            'typeId' => 'required|uuid',
            'allowance' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($error = $this->validateMember($tenantId, $payload['memberId'])) {
            return $error;
        }
        if ($error = $this->validateType($tenantId, $payload['typeId'])) {
            return $error;
        }
        if ($error = $this->validateNoOverlap(
            $tenantId,
            $payload['memberId'],
            $payload['dateFrom'],
            $payload['dateTo']
        )) {
            return $error;
        }

        $record = Whitelisted::create([
            ...$payload,
            'tenantId' => $tenantId,
        ]);

        return response()->json($record->load(['member.group', 'type']));
    }

    public function update(Request $request, string $whitelisted): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Whitelisted::query()->where('id', $whitelisted);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();
        $tenantId = $record->tenantId;

        $payload = $request->validate([
            'memberId' => 'sometimes|required|uuid',
            'dateFrom' => 'sometimes|required|date',
            'dateTo' => 'sometimes|required|date',
            'typeId' => 'sometimes|required|uuid',
            'allowance' => 'sometimes|required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $memberId = $payload['memberId'] ?? $record->memberId;
        $dateFrom = $payload['dateFrom'] ?? $record->dateFrom->toDateString();
        $dateTo = $payload['dateTo'] ?? $record->dateTo->toDateString();

        if ($dateFrom > $dateTo) {
            return response()->json(['error' => 'dateTo must be on or after dateFrom'], 400);
        }

        if (isset($payload['memberId']) && $error = $this->validateMember($tenantId, $payload['memberId'])) {
            return $error;
        }
        if (isset($payload['typeId']) && $error = $this->validateType($tenantId, $payload['typeId'])) {
            return $error;
        }
        if ($error = $this->validateNoOverlap($tenantId, $memberId, $dateFrom, $dateTo, $record->id)) {
            return $error;
        }

        $record->fill($payload);
        $record->save();

        return response()->json($record->load(['member.group', 'type']));
    }

    public function destroy(Request $request, string $whitelisted): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Whitelisted::query()->where('id', $whitelisted);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $query->delete();

        return response()->json(['success' => true]);
    }

    private function validateMember(string $tenantId, string $memberId): ?JsonResponse
    {
        $exists = Member::query()
            ->where('id', $memberId)
            ->where('tenantId', $tenantId)
            ->exists();

        if (!$exists) {
            return response()->json(['error' => 'Invalid member'], 400);
        }

        return null;
    }

    private function validateType(string $tenantId, string $typeId): ?JsonResponse
    {
        $type = Type::query()
            ->where('id', $typeId)
            ->where('tenantId', $tenantId)
            ->where('group', 'members')
            ->first();

        if (!$type) {
            return response()->json(['error' => 'Invalid type (must be member group)'], 400);
        }

        return null;
    }

    private function validateNoOverlap(
        string $tenantId,
        string $memberId,
        string $dateFrom,
        string $dateTo,
        ?string $excludeId = null
    ): ?JsonResponse {
        $query = Whitelisted::query()
            ->where('tenantId', $tenantId)
            ->where('memberId', $memberId)
            ->where('dateFrom', '<=', $dateTo)
            ->where('dateTo', '>=', $dateFrom);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            return response()->json([
                'error' => 'Periode daftar putih bertumpang tindih dengan catatan lain untuk anggota ini',
            ], 400);
        }

        return null;
    }
}
