<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ExpenseRepository;
use App\Repositories\FundsAccountRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettingRepository;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\PaymentBreakdown;
use App\Support\PaymentCode;
use App\Support\Constants;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PublicReportController extends Controller
{
    public function show(Request $request, string $tenantSlug): JsonResponse
    {
        $payload = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $month = (int) $payload['month'];
        $year = (int) $payload['year'];

        $tenant = $this->findTenant($tenantSlug);
        if (!$tenant) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'financialSummary' => $this->financialSummary($tenant->id, $month, $year),
            'transactions' => $this->monthlyTransactions($tenant->id, $month, $year),
        ]);
    }

    public function memberIuran(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->findTenant($tenantSlug);
        if (!$tenant) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $payload = $request->validate([
            'houseNumber' => 'required|string',
            'pin' => 'required|string|min:4|max:32',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $member = $this->findMemberByHouseNumber($tenant->id, $payload['houseNumber']);
        if (!$member || !$this->pinMatches($member, $payload['pin'])) {
            return response()->json(['error' => 'Nomor rumah atau PIN tidak sesuai'], 403);
        }

        return response()->json($this->memberIuranPayload($tenant, $member, (int) $payload['year']));
    }

    public function updateMemberPin(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->findTenant($tenantSlug);
        if (!$tenant) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $payload = $request->validate([
            'houseNumber' => 'required|string',
            'pin' => 'required|string|min:4|max:32',
            'newPin' => 'required|string|min:4|max:32',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $member = $this->findMemberByHouseNumber($tenant->id, $payload['houseNumber']);
        if (!$member || !$this->pinMatches($member, $payload['pin'])) {
            return response()->json(['error' => 'Nomor rumah atau PIN tidak sesuai'], 403);
        }

        $member->update(['memberPinHash' => Hash::make($payload['newPin'])]);

        return response()->json([
            'success' => true,
            'report' => $this->memberIuranPayload(
                $tenant,
                $member->refresh(),
                (int) ($payload['year'] ?? now()->year)
            ),
        ]);
    }

    private function findTenant(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', $slug)->first();
    }

    private function findMemberByHouseNumber(string $tenantId, string $houseNumber): ?Member
    {
        $normalized = $this->normalizeHouseNumber($houseNumber);

        return Member::query()
            ->where('tenantId', $tenantId)
            ->with('group')
            ->get()
            ->first(fn (Member $member) => $this->normalizeHouseNumber($member->houseNumber) === $normalized);
    }

    private function normalizeHouseNumber(string $houseNumber): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($houseNumber)) ?? '');
    }

    private function pinMatches(Member $member, string $pin): bool
    {
        if ($member->memberPinHash) {
            return Hash::check($pin, $member->memberPinHash);
        }

        return hash_equals('123456', $pin);
    }

    private function financialSummary(string $tenantId, int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Jakarta')->startOfDay();
        $lastMonthEnd = clone $monthStart;
        $monthEnd = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Jakarta')->endOfMonth()->endOfDay();

        $fundsAccountRepository = new FundsAccountRepository();
        $openingBalances = $fundsAccountRepository->getBalanceUntil($tenantId, $lastMonthEnd->subDay(), null);
        $closingBalances = $fundsAccountRepository->getBalanceUntil($tenantId, $monthEnd, null);

        $expenseRepository = new ExpenseRepository();
        $monthExpenses = $expenseRepository->getExpensesTypeOnMonth($tenantId, $year, $month);
        $totalExpense = round($monthExpenses->sum('amount'), 2);

        $paymentRepository = new PaymentRepository();
        $monthlyIncomePerGroup = $paymentRepository->getMonthlyIncomePerGroup($tenantId, $year, $month);
        $totalDeposit = $monthlyIncomePerGroup->map(fn ($income) => ($income['details']['deposit'] ?? 0))->sum();
        $totalIncome = round(collect($monthlyIncomePerGroup)->sum('amount'), 2) - $totalDeposit;

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'startDate' => $monthStart->toIso8601String(),
                'endDate' => $monthEnd->toIso8601String(),
                'label' => $monthStart->locale('id')->translatedFormat('F Y'),
            ],
            'summary' => [
                'totalIncome' => $totalIncome,
                'totalExpense' => $totalExpense,
                'totalDeposit' => $totalDeposit,
                'totalDepositExpensed' => round(collect($monthlyIncomePerGroup)->filter(fn($expense) => $expense['groupId'] == '000')->sum('amount'), 2),
                'monthlyDifference' => round($totalIncome - $totalExpense, 2),
                'openingBalance' => round(collect($openingBalances)->filter(fn ($account) => !$account['isDeposit'])->sum('balance'), 2),
                'depositBalanceOpening' => round(collect($openingBalances)->filter(fn ($account) => $account['isDeposit'])->sum('balance'), 2),
                'depositBalance' => round(collect($closingBalances)->filter(fn ($account) => $account['isDeposit'])->sum('balance'), 2),
                'closingBalance' => round(collect($closingBalances)->filter(fn ($account) => !$account['isDeposit'])->sum('balance'), 2),
            ],
            'incomeByGroup' => $monthlyIncomePerGroup->all(),
            'expensesByType' => $monthExpenses
                ->sortBy('type')
                ->values()
                ->all(),
            'openingBalancesByAccount' => $openingBalances,
            'closingBalancesByAccount' => $closingBalances,
        ];
    }

    private function monthlyTransactions(string $tenantId, int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        $expenseRepository = new ExpenseRepository();
        $expenses = $expenseRepository->getExpenses(['tenantId' => $tenantId, 'betweenDate' => [$monthStart, $monthEnd]]);

        $paymentRepository = new PaymentRepository();
        $payments = $paymentRepository->getPayments(['tenantId' => $tenantId, 'betweenDate' => [$monthStart, $monthEnd]]);

        $depositRows = $payments->filter(fn($payment) => $payment['fundsAccountName'] == 'Deposit');
        $incomeRows = $payments->filter(fn($payment) => $payment['fundsAccountName'] != 'Deposit');

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'label' => $monthStart->locale('id')->translatedFormat('F Y'),
            ],
            'incomeTransactions' => collect($incomeRows)->sortBy('date')->values()->all(),
            'expenseTransactions' => collect($expenses)->map(fn($expense) => [...$expense, 'fundsAccountName' => $expense['sourceOfFundsCompacted']]),
            'depositTransactions' => collect($depositRows)->sortBy('date')->values()->all(),
        ];
    }

    private function memberIuranPayload(Tenant $tenant, Member $member, int $year): array
    {
        $settingRepository = new \App\Repositories\SettingRepository();
        $firstPaymentDate = $settingRepository->getSetting($tenant->id, Constants::SETTING_PAYMENT_COLLECTION_STARTDATE);

        $payments = Payment::query()
            ->where('tenantId', $tenant->id)
            ->whereIn('code', [PaymentCode::MONTHLY_PAYMENT, PaymentCode::COLLECTIVE_PAYMENT])
            ->where('status', 'paid')
            ->with('breakdowns')
            ->whereHas('breakdowns', fn ($q) => $q->where('memberId', $member->id)->where('year', $year))
            ->get();

        $monthlyPaymentsStatus = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

            $breakdowns = $payments->flatMap->breakdowns->filter(
                fn (PaymentBreakdown $b) =>
                    $b->memberId === $member->id
                    && (int) $b->month === $month
                    && (int) $b->year === $year
            );

             if ($breakdowns->isEmpty() && $firstPaymentDate && $monthEnd->lt(Carbon::parse($firstPaymentDate))) {
                $monthlyPaymentsStatus[] = [
                    'month' => $month,
                    'year' => $year,
                    'status' => 'not_due',
                    'paidAmount' => 0,
                ];
                continue;
            }

            $monthlyPaymentsStatus[] = [
                'firstPaymentDate' => $firstPaymentDate,
                'month' => $month,
                'year' => $year,
                'status' => $breakdowns->isNotEmpty() ? 'paid' : 'unpaid',
                'paidAmount' => round($breakdowns->sum('amount'), 2),
            ];
        }

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'houseNumber' => $member->houseNumber,
                'groupName' => $member->group?->name,
            ],
            'year' => $year,
            'monthlyPaymentsStatus' => $monthlyPaymentsStatus,
        ];
    }
}
