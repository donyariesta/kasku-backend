<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\User;
use App\Support\Roles;
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
            ->with('Group')
            ->orderBy('Group.name')
            ->orderBy('Member.houseNumber');

        if ($tenantId) {
            $query->where('Member.tenantId', $tenantId);
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
