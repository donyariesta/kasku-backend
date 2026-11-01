<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\Type;
use App\Models\User;
use App\Models\Whitelisted;
use App\Repositories\TypeRepository;
use App\Support\Roles;
use App\Support\TypeCode;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Member::query()
            ->select('Member.*')
            ->join('Group', 'Group.id', '=', 'Member.groupId')
            ->with(['group', 'whitelisted' => function ($q): void {
                $today = Carbon::today()->toDateString();
                $q->with('type')
                    ->where('dateFrom', '<=', $today)
                    ->where(fn ($range) => $range->whereNull('dateTo')->orWhere('dateTo', '>=', $today))
                    ->orderByDesc('dateFrom');
            }])
            ->orderBy('Group.name')
            ->orderBy('Member.houseNumber');

        if ($tenantId) {
            $query->where('Member.tenantId', $tenantId);
        }

        $members = $query->get()->map(function (Member $member) {
            $isVacantType = fn (Whitelisted $row) => $row->type?->isSystem
                && $row->type->code === TypeCode::MEMBER_VACANT;
            $openVacant = $member->whitelisted->first(
                fn (Whitelisted $row) => $isVacantType($row) && $row->dateTo === null
            );
            $member->setAttribute('isVacant', $openVacant !== null);
            $member->setAttribute('vacantWhitelistId', $openVacant?->id);
            $member->setAttribute(
                'isWhitelisted',
                $member->whitelisted->contains(fn (Whitelisted $row) => !$isVacantType($row))
            );
            $member->unsetRelation('whitelisted');

            return $member;
        });

        return response()->json($members);
    }

    public function toggleVacant(Request $request, string $member): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Member::query()->where('id', $member);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();
        $typeId = (new TypeRepository())->getSystemTypeId($record->tenantId, TypeCode::MEMBER_VACANT);
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $previousMonthEnd = Carbon::now()->startOfMonth()->subDay()->toDateString();

        $openVacant = Whitelisted::query()
            ->where('tenantId', $record->tenantId)
            ->where('memberId', $record->id)
            ->where('typeId', $typeId)
            ->whereNull('dateTo')
            ->orderByDesc('dateFrom')
            ->first();

        if ($openVacant) {
            $dateFrom = $openVacant->dateFrom->toDateString();
            if ($previousMonthEnd < $dateFrom) {
                $openVacant->delete();
            } else {
                $openVacant->dateTo = $previousMonthEnd;
                $openVacant->save();
            }

            return response()->json([
                'success' => true,
                'isVacant' => false,
                'memberId' => $record->id,
            ]);
        }

        Whitelisted::create([
            'tenantId' => $record->tenantId,
            'memberId' => $record->id,
            'dateFrom' => $monthStart,
            'dateTo' => null,
            'typeId' => $typeId,
            'allowance' => 100,
            'notes' => 'Kosong',
        ]);

        return response()->json([
            'success' => true,
            'isVacant' => true,
            'memberId' => $record->id,
        ]);
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
            'name' => 'required|string',
            'houseNumber' => 'required|string',
            'phoneNumber' => 'nullable|string',
            'status' => 'required|string',
            'groupId' => 'nullable|uuid',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        try {
            $member = DB::transaction(function () use ($payload, $tenantId) {
                $userId = null;
                if (!empty($payload['username']) && !empty($payload['password'])) {
                    $user = User::create([
                        'username' => $payload['username'],
                        'password' => Hash::make($payload['password']),
                        'name' => $payload['name'],
                        'role' => Roles::MEMBER,
                        'tenantId' => $tenantId,
                    ]);
                    $userId = $user->id;
                }

                return Member::create([
                    'name' => $payload['name'],
                    'houseNumber' => $payload['houseNumber'],
                    'phoneNumber' => $payload['phoneNumber'] ?? null,
                    'status' => $payload['status'],
                    'tenantId' => $tenantId,
                    'groupId' => $payload['groupId'] ?? null,
                    'userId' => $userId,
                ])->load('group');
            });

            return response()->json($member);
        } catch (QueryException) {
            return response()->json(['error' => 'Username already exists or invalid data'], 400);
        }
    }

    public function update(Request $request, string $member): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Member::query()->where('id', $member);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();
        $record->fill($request->only(['name', 'houseNumber', 'phoneNumber', 'status', 'groupId']));
        $record->save();

        return response()->json($record->load('group'));
    }

    public function destroy(Request $request, string $member): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Member::query()->where('id', $member);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        try {
            $query->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete member: linked to payments or other records.'], 400);
        }
    }
}
