<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Models\FundsAccount;
use App\Models\FundsTransfer;
use App\Models\Group;
use App\Models\Member;
use App\Models\Payment;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialSummaryController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID is required'], 400);
        }

        $payload = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $month = (int) $payload['month'];
        $year = (int) $payload['year'];
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
            ->filter(fn (FundsAccount $a) => !($a->isSystem && $a->name === FundsAccount::DEPOSIT_NAME));

        $payments = Payment::query()
            ->where('tenantId', $tenantId)
            ->where('status', 'paid')
            ->with('breakdowns')
            ->get();

        $expenses = Expense::query()
            ->where('tenantId', $tenantId)
            ->get();

        $transfers = FundsTransfer::query()
            ->where('tenantId', $tenantId)
            ->get();

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

                $memberId = $breakdown->memberId ?? $payment->memberId;
                $member = $members->get($memberId);
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

        $incomeByGroupRows = collect($incomeByGroup)
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->sortBy('groupName')
            ->values()
            ->map(fn (array $row) => [
                'groupId' => $row['groupId'],
                'groupName' => $row['groupName'],
                'amount' => round($row['amount'], 2),
            ])
            ->all();

        $monthExpenses = $expenses->filter(
            fn (Expense $e) => Carbon::parse($e->date)->between($monthStart, $monthEnd)
        );

        $totalExpense = round($monthExpenses->sum('amount'), 2);

        $expensesByCategory = $monthExpenses
            ->groupBy(fn (Expense $e) => $e->category ?: 'Lainnya')
            ->map(fn ($items, string $category) => [
                'category' => $category,
                'amount' => round($items->sum('amount'), 2),
            ])
            ->sortBy('category')
            ->values()
            ->all();

        $openingBalances = [];
        $closingBalances = [];

        foreach ($fundsAccounts as $account) {
            $opening = $this->accountBalanceUntil(
                $account->id,
                $payments,
                $expenses,
                $transfers,
                $monthStart,
                false
            );
            $closing = $this->accountBalanceUntil(
                $account->id,
                $payments,
                $expenses,
                $transfers,
                $monthEnd,
                true
            );

            $openingBalances[] = [
                'fundsAccountId' => $account->id,
                'fundsAccountName' => $account->name,
                'amount' => round($opening, 2),
            ];
            $closingBalances[] = [
                'fundsAccountId' => $account->id,
                'fundsAccountName' => $account->name,
                'amount' => round($closing, 2),
            ];
        }

        $openingTotal = round(collect($openingBalances)->sum('amount'), 2);
        $closingTotal = round(collect($closingBalances)->sum('amount'), 2);
        $monthlyDifference = round($totalIncome - $totalExpense, 2);

        return response()->json([
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
                'monthlyDifference' => $monthlyDifference,
                'openingBalance' => $openingTotal,
                'closingBalance' => $closingTotal,
            ],
            'incomeByGroup' => $incomeByGroupRows,
            'expensesByCategory' => $expensesByCategory,
            'openingBalancesByAccount' => $openingBalances,
            'closingBalancesByAccount' => $closingBalances,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @param  \Illuminate\Support\Collection<int, Expense>  $expenses
     * @param  \Illuminate\Support\Collection<int, FundsTransfer>  $transfers
     */
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
                if ($breakdown->fundsAccountId !== $accountId) {
                    continue;
                }
                $income += (float) $breakdown->amount;
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
