<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\ExpenseSourceOfFunds;
use App\Models\PaymentBreakdown;
use App\Models\PaymentAux;
use App\Models\PaymentMember;
use App\Repositories\TypeRepository;
use App\Repositories\GroupRepository;
use App\Repositories\FundsAccountRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettingRepository;
use App\Support\PaymentCode;
use App\Support\Roles;
use App\Support\TypeCode;
use App\Support\Constants;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $paymentRepository = new PaymentRepository();

        $filter = [
            'tenantId' => $tenantId,
        ];

        if ($request->filled('fundsAccountId')) {
            $filter['fundsAccountId'] = $request->query('fundsAccountId');
        }

        if ($request->filled('year')) {
            $filter['year'] = $request->query('year');
        }

        if ($request->filled('month')) {
            $filter['month'] = $request->query('month');
        }

        if ($request->filled('groupId')) {
            $filter['groupId'] = $request->query('groupId');
        }

        if ($request->filled('fundsAccountId')) {
            $filter['fundsAccountId'] = $request->query('fundsAccountId');
        }

        $paymentList = collect($paymentRepository->getPaymentList($filter))->map(function ($payment) {
            $payment->groupName = !empty($payment->groupName) ? $payment->groupName : PaymentCode::getName($payment->code);
            $payment->year = !empty($payment->year) ? $payment->year : carbon::parse($payment->date)->year;
            $payment->month = !empty($payment->month) ? $payment->month : carbon::parse($payment->date)->month;
            return $payment;
        });

        return response()->json($paymentList);
    }

    public function getFormData(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $settingRepository = new SettingRepository();

        $members = Member::query()
            ->where('tenantId', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $fundsAccounts = FundsAccount::query()
            ->where('tenantId', $tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'paymentCodeOptions' => PaymentCode::getOptions(),
            'members' => $members,
            'fundsAccounts' => $fundsAccounts,
            'settings' => [
                'tenantType' => $settingRepository->getSetting($tenantId, Constants::SETTING_TENANT_TYPE) ?? Constants::TENANT_TYPE_NEIGHBORHOOD,
            ],
        ]);
    }

    public function getEditPayorFormData(Request $request, $paymentId): JsonResponse
    {
        $paymentAux = PaymentAux::query()
            ->where('paymentId', $paymentId)
            ->first();

        $groupRepository = new \App\Repositories\GroupRepository();
        $monthlyPayments = $groupRepository->getMonthlyPayments([
            'tenantId' => $paymentAux->tenantId,
            'groupId' => $paymentAux->groupId,
            'year' => $paymentAux->year,
            'month' => $paymentAux->month,
        ]);

         return response()->json([
            'paymentId' => $paymentAux->paymentId,
            'totalMember' => $paymentAux->totalMember,
            'amountPerMember' => $paymentAux->amountPerMember,
            'count' => count($monthlyPayments),
            'membersBill' => $monthlyPayments,
         ]);
    }

    public function updatePaymentPayor(Request $request, $paymentId): JsonResponse
    {
        $payload = $request->validate([
            'selectedMembers' => 'nullable|array',
        ]);

        $selectedMembers = collect($payload['selectedMembers'])->unique()->values()->all();

        $paymentAux = PaymentAux::query()
            ->where('paymentId', $paymentId)
            ->first();

        $existingMemberIds = PaymentMember::query()
            ->where('paymentId', $paymentAux->paymentId)
            ->whereIn('memberId', $selectedMembers)
            ->get()->pluck('memberId')->toArray();

        PaymentMember::query()
            ->where('paymentId', $paymentAux->paymentId)
            ->whereNotIn('memberId', $selectedMembers)
            ->delete();

        foreach ($selectedMembers as $memberId) {
            if (in_array($memberId, $existingMemberIds)) {
                continue;
            }

            PaymentMember::create([
                'tenantId' => $paymentAux->tenantId,
                'memberId' => $memberId,
                'paymentId' => $paymentAux->paymentId,
                'month' => $paymentAux->month,
                'year' => $paymentAux->year,
                'amount' => $paymentAux->amountPerMember,
                'paymentBreakdown' => [],
            ]);
        }

        return response()->json($paymentAux->payment);
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
            'payerMemberId' => 'required|uuid',
            'paymentDate' => 'nullable|date',
            'year' => 'required|numeric|min:0',
            'month' => 'required|numeric|min:0',
            'groupId' => 'required|uuid',
            'checklistIncluded' => 'required|boolean',
            'incentiveAmount' => 'nullable|numeric|min:0',
            'numberOfPaid' => 'nullable|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'status' => 'nullable|string',
            'code' => 'nullable|integer|in:' . implode(',', array_keys(PaymentCode::getOptions())),
            'notes' => 'nullable|string',
            'selectedMembers' => 'nullable|array',
            'selectedOweMembers' => 'nullable|array',
            'memberPaymentBreakdown' => 'nullable|array',
            'memberPaymentBreakdown.*.amount' => 'required|numeric|min:0',
            'memberPaymentBreakdown.*.fundsAccountId' => 'required|uuid',
            'breakdowns' => 'nullable|array',
            'breakdowns.*.amount' => 'required|numeric|min:0',
            'breakdowns.*.fundsAccountId' => 'required|uuid',
            'breakdowns.*.notes' => 'nullable|string',
        ]);

        if ($error = $this->checkIsAmmountValid($tenantId, $payload)) {
            return $error;
        }

        if ($error = $this->validateMember($tenantId, $payload['payerMemberId'])) {
            return $error;
        }

        $paymentDate = $payload['paymentDate'] ?? now();
        $code = (int) ($payload['code'] ?? PaymentCode::MONTHLY_PAYMENT);

        $payment = DB::transaction(function () use ($payload, $tenantId, $request, $code, $paymentDate) {
            $payment = Payment::create([
                'memberId' => $payload['payerMemberId'],
                'amount' => $payload['amount'],
                'date' => $paymentDate,
                'tenantId' => $tenantId,
                'treasurerId' => $request->user()->id,
                'status' => $payload['status'] ?? 'paid',
                'code' => $code,
                'notes' => $payload['notes'] ?? null,
            ]);

            $paymentAux = PaymentAux::create([
                'tenantId' => $tenantId,
                'paymentId' => $payment->id,
                'year' => $payload['year'],
                'month' => $payload['month'],
                'groupId' => $payload['groupId'],
                'incentiveAmount' => $payload['incentiveAmount'],
                'amountPerMember' => collect($payload['memberPaymentBreakdown'])->sum('amount'),
                'totalMember' => $payload['checklistIncluded'] ? count($payload['selectedMembers']) : $payload['numberOfPaid'],
            ]);

            foreach ($payload['breakdowns'] as $breakdown) {
                PaymentBreakdown::create([
                    'paymentId' => $payment->id,
                    'amount' => $breakdown['amount'],
                    'fundsAccountId' => $breakdown['fundsAccountId'],
                    'notes' => $breakdown['notes'] ?? null,
                ]);
            }

            foreach ($payload['selectedMembers'] as $memberId) {
                PaymentMember::create([
                    'tenantId' => $tenantId,
                    'memberId' => $memberId,
                    'paymentId' => $payment->id,
                    'month' => $payload['month'],
                    'year' => $payload['year'],
                    'amount' => collect($payload['memberPaymentBreakdown'])->sum('amount'),
                    'paymentBreakdown' => $payload['memberPaymentBreakdown'] ?? [],
                ]);
            }

            $fundsAccountRepository = new FundsAccountRepository();
            $defaultFundAccount = $fundsAccountRepository->getDefaultFundsAccount($tenantId);
            $defaultFundAccountId = $defaultFundAccount->id;

            $paymentRepository = new PaymentRepository();

            foreach ($payload['selectedOweMembers'] as $oweMember) {
                $paymentRecord = $paymentRepository->getPaymentMemberRecord($tenantId, $oweMember['memberId'], $payload['year'], $payload['month']);
                PaymentBreakdown::create([
                    'paymentId' => $payment->id,
                    'amount' => $oweMember['amountOwed'],
                    'fundsAccountId' => $defaultFundAccountId,
                    'notes' => 'Pelunasan',
                ]);

                $paymentRecord->amount += $oweMember['amountOwed'];
                $paymentRecord->save();
            }

            return $payment;
        });

        return response()->json($payment->load(['member', 'breakdowns.fundsAccount']));
    }

    public function storeSinglePayment(Request $request): JsonResponse
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
            'fundsAccountId' => 'required|uuid',
            'amount' => 'required|numeric|min:0',
            'code' => 'nullable|integer|in:' . implode(',', array_keys(PaymentCode::getOptions())),
            'status' => 'nullable|string',
            'date' => 'nullable|date',
            'notes' => 'nullable|string',
            'year' => 'nullable|integer',
            'month' => 'nullable|integer',
        ]);

        if ($error = $this->validateMember($tenantId, $payload['memberId'])) {
            return $error;
        }

        if ($error = $this->validateFundsAccount($tenantId, $payload['fundsAccountId'])) {
            return $error;
        }

        $paymentDate = $payload['date'] ?? now();
        $settingRepository = new SettingRepository();

        $payment = DB::transaction(function () use ($payload, $tenantId, $request, $paymentDate, $settingRepository) {
            $payment = Payment::create([
                'memberId' => $payload['memberId'],
                'amount' => $payload['amount'],
                'date' => $paymentDate,
                'tenantId' => $tenantId,
                'treasurerId' => $request->user()->id,
                'status' => $payload['status'] ?? 'paid',
                'code' => $payload['code'] ?? PaymentCode::MONTHLY_PAYMENT,
                'notes' => $payload['notes'] ?? null,
            ]);

            $member = Member::query()
                ->where('id', $payload['memberId'])
                ->where('tenantId', $tenantId)
                ->first();

            PaymentBreakdown::create([
                'paymentId' => $payment->id,
                'amount' => $payload['amount'],
                'fundsAccountId' => $payload['fundsAccountId'],
                'notes' => $payload['notes'] ?? null,
            ]);

            $tenantType = $settingRepository->getSetting($tenantId, Constants::SETTING_TENANT_TYPE) ?? Constants::TENANT_TYPE_NEIGHBORHOOD;
            if ($tenantType === Constants::TENANT_TYPE_NEIGHBORHOOD and $payload['code'] === PaymentCode::MONTHLY_PAYMENT) {
                $paymentAux = PaymentAux::create([
                    'tenantId' => $tenantId,
                    'paymentId' => $payment->id,
                    'year' => $payload['year'],
                    'month' => $payload['month'],
                    'groupId' => $member->groupId,
                    'incentiveAmount' => 0,
                    'totalMember' => 1,
                    'amountPerMember' => $payload['amount'],
                ]);

                $paymentRecord = $paymentRepository->getPaymentMemberRecord($tenantId, $payload['memberId'], $payload['year'], $payload['month']);
                if (!$paymentRecord) {
                    PaymentMember::create([
                        'tenantId' => $tenantId,
                        'memberId' => $payload['memberId'],
                        'paymentId' => $payment->id,
                        'month' => $payload['month'],
                        'year' => $payload['year'],
                        'amount' => $payload['amount'],
                        'paymentBreakdown' => [
                            [
                                'amount' => $payload['amount'],
                                'fundsAccountId' => $payload['fundsAccountId'],
                            ],
                        ],
                    ]);
                } else {
                    $paymentRecord->amount += $payload['amount'];
                    $paymentRecord->save();
                }
            }

            return $payment;
        });

        return response()->json($payment->load(['member', 'breakdowns.fundsAccount']));
    }

    private function checkIsAmmountValid($tenantId, $payload)
    {
        $breakdownTotal = collect($payload['breakdowns'])->sum('amount');
        $totalAmountOwed = collect($payload['selectedOweMembers'])->sum('amountOwed');
        if (round($breakdownTotal, 2) + round($totalAmountOwed, 2) !== round((float) $payload['amount'], 2)) {
            return response()->json(['error' => 'Breakdown amounts must sum to payment amount'], 400);
        }

        foreach ($payload['breakdowns'] as $breakdown) {
            if ($error = $this->validateFundsAccount($tenantId, $breakdown['fundsAccountId'])) {
                return $error;
            }
        }

        return null;
    }

    private function validateFundsAccount(string $tenantId, string $fundsAccountId): ?JsonResponse
    {
        $account = FundsAccount::query()
            ->where('id', $fundsAccountId)
            ->where('tenantId', $tenantId)
            ->where('active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Invalid or inactive funds account'], 400);
        }

        return null;
    }

    private function validateMember(string $tenantId, string $memberId): ?JsonResponse
    {
        $member = Member::query()
            ->where('id', $memberId)
            ->where('tenantId', $tenantId)
            ->first();

        if (!$member) {
            return response()->json(['error' => 'Invalid member'], 400);
        }

        return null;
    }

    public function destroy(Request $request, string $payment): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        try {
            $paymentRepository = new PaymentRepository();
            $paymentRepository->delete($request->user(), $payment);
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete payment: referenced by other records.'], 400);
        }
    }
}
