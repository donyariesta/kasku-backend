<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\FundsAccount;
use App\Models\FundsTransfer;
use App\Models\Group;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentBreakdown;
use App\Models\Tenant;
use App\Support\PaymentCode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PublicReportController extends Controller
{
    public function show(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->findTenant($tenantSlug);
        if (!$tenant) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $payload = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $month = (int) $payload['month'];
        $year = (int) $payload['year'];

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
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        $members = Member::query()
            ->where('tenantId', $tenantId)
            ->with('group')
            ->get()
            ->keyBy('id');

        $groups = Group::query()
            ->where('tenantId', $tenantId)
            ->orderBy('name')
            ->get();

        $fundsAccounts = FundsAccount::query()
            ->where('tenantId', $tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (FundsAccount $a) => !$a->isDeposit());

        $payments = Payment::query()
            ->where('tenantId', $tenantId)
            ->where('status', 'paid')
            ->with('breakdowns')
            ->get();

        $expenses = Expense::query()->where('tenantId', $tenantId)->get();
        $transfers = FundsTransfer::query()->where('tenantId', $tenantId)->get();

        $monthPayments = $payments->filter(
            fn (Payment $p) => Carbon::parse($p->date)->between($monthStart, $monthEnd)
        );

        $totalIncome = 0.0;
        $incomeByGroup = [];
        foreach ($groups as $group) {
            $incomeByGroup[$group->id] = [
                'groupId' => $group->id,
                'groupName' => $group->name,
                'amount' => 0.0,
            ];
        }
        $incomeByGroup['__none__'] = [
            'groupId' => null,
            'groupName' => 'Tanpa Grup',
            'amount' => 0.0,
        ];

        foreach ($monthPayments as $payment) {
            foreach ($payment->breakdowns as $breakdown) {
                $amount = (float) $breakdown->amount;
                $totalIncome += $amount;
                $member = $members->get($breakdown->memberId ?? $payment->memberId);
                $groupKey = $member?->groupId ?? '__none__';
                if (!isset($incomeByGroup[$groupKey])) {
                    $incomeByGroup[$groupKey] = [
                        'groupId' => $member?->groupId,
                        'groupName' => $member?->group?->name ?? 'Tanpa Grup',
                        'amount' => 0.0,
                    ];
                }
                $incomeByGroup[$groupKey]['amount'] += $amount;
            }
        }

        $monthExpenses = $expenses->filter(
            fn (Expense $e) => Carbon::parse($e->date)->between($monthStart, $monthEnd)
        );

        $openingBalances = [];
        $closingBalances = [];
        foreach ($fundsAccounts as $account) {
            $openingBalances[] = [
                'fundsAccountId' => $account->id,
                'fundsAccountName' => $account->name,
                'amount' => round($this->accountBalanceUntil($account->id, $payments, $expenses, $transfers, $monthStart, false), 2),
            ];
            $closingBalances[] = [
                'fundsAccountId' => $account->id,
                'fundsAccountName' => $account->name,
                'amount' => round($this->accountBalanceUntil($account->id, $payments, $expenses, $transfers, $monthEnd, true), 2),
            ];
        }

        $totalExpense = round($monthExpenses->sum('amount'), 2);

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'startDate' => $monthStart->toIso8601String(),
                'endDate' => $monthEnd->toIso8601String(),
                'label' => $monthStart->locale('id')->translatedFormat('F Y'),
            ],
            'summary' => [
                'totalIncome' => round($totalIncome, 2),
                'totalExpense' => $totalExpense,
                'monthlyDifference' => round($totalIncome - $totalExpense, 2),
                'openingBalance' => round(collect($openingBalances)->sum('amount'), 2),
                'closingBalance' => round(collect($closingBalances)->sum('amount'), 2),
            ],
            'incomeByGroup' => collect($incomeByGroup)
                ->filter(fn (array $row) => $row['amount'] > 0)
                ->sortBy('groupName')
                ->values()
                ->map(fn (array $row) => [
                    'groupId' => $row['groupId'],
                    'groupName' => $row['groupName'],
                    'amount' => round($row['amount'], 2),
                ])
                ->all(),
            'expensesByCategory' => $monthExpenses
                ->groupBy(fn (Expense $e) => $e->category ?: 'Lainnya')
                ->map(fn ($items, string $category) => [
                    'category' => $category,
                    'amount' => round($items->sum('amount'), 2),
                ])
                ->sortBy('category')
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

        $payments = Payment::query()
            ->where('tenantId', $tenantId)
            ->where('status', 'paid')
            ->with(['member.group', 'breakdowns.fundsAccount', 'breakdowns.member.group'])
            ->get();
        $expenses = Expense::query()
            ->where('tenantId', $tenantId)
            ->with('fundsAccount')
            ->get();
        $transfers = FundsTransfer::query()->where('tenantId', $tenantId)->get();
        $accounts = FundsAccount::query()->where('tenantId', $tenantId)->get();
        $depositAccountIds = $accounts
            ->filter(fn (FundsAccount $account) => $account->isDeposit())
            ->pluck('id')
            ->all();
        $operationalAccounts = $accounts->filter(fn (FundsAccount $account) => !$account->isDeposit());

        $lastMonthBalance = round($operationalAccounts->sum(
            fn (FundsAccount $account) => $this->accountBalanceUntil($account->id, $payments, $expenses, $transfers, $monthStart, false)
        ), 2);

        $incomeRows = [];
        $depositRows = [];
        foreach ($payments->filter(fn (Payment $p) => Carbon::parse($p->date)->between($monthStart, $monthEnd)) as $payment) {
            $depositBreakdowns = $payment->breakdowns->filter(
                fn (PaymentBreakdown $breakdown) => in_array($breakdown->fundsAccountId, $depositAccountIds, true)
            );
            $incomeBreakdowns = $payment->breakdowns->reject(
                fn (PaymentBreakdown $breakdown) => in_array($breakdown->fundsAccountId, $depositAccountIds, true)
            );

            if ($incomeBreakdowns->isNotEmpty()) {
                $incomeRows[] = $this->paymentTransactionRow($payment, $incomeBreakdowns, 'income');
            }

            if ($depositBreakdowns->isNotEmpty()) {
                $depositRows[] = $this->paymentTransactionRow($payment, $depositBreakdowns, 'deposit');
            }
        }

        $expenseRows = $expenses
            ->filter(fn (Expense $e) => Carbon::parse($e->date)->between($monthStart, $monthEnd))
            ->sortBy('date')
            ->values()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'date' => Carbon::parse($expense->date)->toDateString(),
                'description' => $expense->title,
                'category' => $expense->category,
                'fundsAccountName' => $expense->fundsAccount?->name,
                'amount' => round((float) $expense->amount, 2),
            ])
            ->all();

        $totalIncome = round(collect($incomeRows)->sum('amount'), 2);
        $totalExpenses = round(collect($expenseRows)->sum('amount'), 2);
        $totalDeposit = round(collect($depositRows)->sum('amount'), 2);

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'label' => $monthStart->locale('id')->translatedFormat('F Y'),
            ],
            'summary' => [
                'lastMonthBalance' => $lastMonthBalance,
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'totalDeposit' => $totalDeposit,
                'endBalanceExcludingDeposit' => round($lastMonthBalance + $totalIncome - $totalExpenses, 2),
            ],
            'incomeTransactions' => collect($incomeRows)->sortBy('date')->values()->all(),
            'expenseTransactions' => $expenseRows,
            'depositTransactions' => collect($depositRows)->sortBy('date')->values()->all(),
        ];
    }

    private function paymentTransactionRow(Payment $payment, $breakdowns, string $type): array
    {
        return [
            'id' => $type . '-' . $payment->id,
            'date' => Carbon::parse($payment->date)->toDateString(),
            'description' => $this->paymentDescription($payment, $breakdowns),
            'memberName' => $payment->member?->name,
            'houseNumber' => $payment->member?->houseNumber,
            'groupName' => $payment->member?->group?->name ?? 'Tanpa Grup',
            'fundsAccountName' => $this->fundsAccountLabel($breakdowns),
            'amount' => round((float) $breakdowns->sum('amount'), 2),
        ];
    }

    private function fundsAccountLabel($breakdowns): string
    {
        $names = $breakdowns
            ->map(fn (PaymentBreakdown $breakdown) => $breakdown->fundsAccount?->name)
            ->filter()
            ->unique()
            ->values();

        if ($names->count() === 0) {
            return '-';
        }

        if ($names->count() === 1) {
            return (string) $names->first();
        }

        return 'Semua pos';
    }

    private function paymentDescription(Payment $payment, $breakdowns): string
    {
        if ((int) $payment->code === PaymentCode::DONATION) {
            return $payment->notes ?: 'Donasi / sponsor';
        }

        $periods = $breakdowns
            ->map(fn (PaymentBreakdown $breakdown) =>
                Carbon::createFromDate(
                    (int) $breakdown->year,
                    (int) $breakdown->month,
                    1
                )->format('M Y')
            )
            ->unique()
            ->values()
            ->implode(', ');

        if ((int) $payment->code === PaymentCode::COLLECTIVE_PAYMENT) {
            return ($payment->notes ?: 'Pembayaran kolektif') . ': ' . $periods;
        }

        return 'Iuran ' . $periods;
    }

    private function memberIuranPayload(Tenant $tenant, Member $member, int $year): array
    {
        $payments = Payment::query()
            ->where('tenantId', $tenant->id)
            ->whereIn('code', [PaymentCode::MONTHLY_PAYMENT, PaymentCode::COLLECTIVE_PAYMENT])
            ->where('status', 'paid')
            ->with('breakdowns')
            ->whereHas('breakdowns', fn ($q) => $q->where('memberId', $member->id)->where('year', $year))
            ->get();

        $monthlyPaymentsStatus = [];
        for ($month = 1; $month <= 12; $month++) {
            $breakdowns = $payments->flatMap->breakdowns->filter(
                fn (PaymentBreakdown $b) =>
                    $b->memberId === $member->id
                    && (int) $b->month === $month
                    && (int) $b->year === $year
            );
            $monthlyPaymentsStatus[] = [
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

    private function accountBalanceUntil(
        string $accountId,
        $payments,
        $expenses,
        $transfers,
        Carbon $cutoff,
        bool $inclusive
    ): float {
        $income = 0.0;
        foreach ($payments as $payment) {
            if ($payment->status !== 'paid') {
                continue;
            }
            $paymentDate = Carbon::parse($payment->date);
            if ($inclusive ? $paymentDate->gt($cutoff) : $paymentDate->gte($cutoff)) {
                continue;
            }

            foreach ($payment->breakdowns as $breakdown) {
                if ($breakdown->fundsAccountId === $accountId) {
                    $income += (float) $breakdown->amount;
                }
            }
        }

        $spent = $expenses
            ->filter(function (Expense $expense) use ($cutoff, $inclusive, $accountId) {
                if ($expense->fundsAccountId !== $accountId) {
                    return false;
                }
                $date = Carbon::parse($expense->date);

                return $inclusive ? $date->lte($cutoff) : $date->lt($cutoff);
            })
            ->sum('amount');

        $transferIn = $transfers
            ->filter(function (FundsTransfer $transfer) use ($cutoff, $inclusive, $accountId) {
                if ($transfer->toFundsAccountId !== $accountId) {
                    return false;
                }
                $date = Carbon::parse($transfer->date);

                return $inclusive ? $date->lte($cutoff) : $date->lt($cutoff);
            })
            ->sum('amount');

        $transferOut = $transfers
            ->filter(function (FundsTransfer $transfer) use ($cutoff, $inclusive, $accountId) {
                if ($transfer->fromFundsAccountId !== $accountId) {
                    return false;
                }
                $date = Carbon::parse($transfer->date);

                return $inclusive ? $date->lte($cutoff) : $date->lt($cutoff);
            })
            ->sum('amount');

        return $income + $transferIn - $spent - $transferOut;
    }
}
