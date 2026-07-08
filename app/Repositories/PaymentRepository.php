<?php

namespace App\Repositories;

use App\Models\ExpenseRepository;
use App\Models\ExpenseSourceOfFunds;
use App\Models\PaymentBreakdown;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\FundsTransfer;
use App\Models\FundsAccount;
use App\Models\Group;
use Carbon\Carbon;
use App\Repositories\FundsAccountRepository;
use App\Support\PaymentCode;
use Illuminate\Support\Facades\DB;

class PaymentRepository
{
    public function getKPI($tenantId, $year, $month = 0)
    {
        if (empty($month)) {
            $month = 0;
        }

        return DB::select("
WITH RECURSIVE months AS (
    SELECT 1 AS month
    UNION ALL
    SELECT month + 1
    FROM months
    WHERE month < 12
)
, FilterValues AS (
    SELECT
        ? tenantId
        , ? year
        , ? month
)
, GroupTarget AS (
    SELECT g.id
        , g.name
    FROM `Group` g
        JOIN FilterValues fv ON fv.tenantId = g.tenantId
)
, TargetMonths AS (
    SELECT fv.year
        , m.month
        , LAST_DAY(CONCAT(fv.year, '-', LPAD(m.month, m.month + 1, '0'), '-01')) lastDate
    FROM months m
        JOIN FilterValues fv ON fv.month = 0 OR fv.month = m.month
)
, Payments AS (
    SELECT pb.memberId
        , pb.year
        , pb.month
        , sum(pb.amount) amount
    FROM PaymentBreakdown pb
        JOIN Payment p ON p.id = pb.paymentId
        JOIN FilterValues fv ON (
            fv.year = pb.year
            AND (fv.month = 0 OR fv.month = pb.month)
            AND fv.tenantId = p.tenantId
        )
    GROUP BY memberId
        , pb.year
        , pb.month
)
, PaymentSummary AS (
    SELECT g.id as groupId
        , p.year
        , p.month
        , count(m.id) numberOfPayor
        , sum(p.amount) amount
    FROM payments as p
        JOIN Member m ON m.id = p.memberId
        JOIN GroupTarget g ON g.id = m.groupId
    GROUP BY g.id
        , p.year
        , p.month
)
, GroupMember AS (
    SELECT m.groupId
        , count(m.id) numberOfMember
    FROM Member m
        JOIN FilterValues fv ON fv.tenantId = m.tenantId
    WHERE m.status = ?
    GROUP BY m.groupId
)
, WhitelistedSummary AS (
    SELECT YEAR(tm.lastDate) year
        , tm.month
        , m.groupId
        , count(wl.memberId) numberOfWaitlisted
    FROM TargetMonths tm
        JOIN Whitelisted wl ON (
            tm.lastDate BETWEEN wl.dateFrom AND wl.dateTo
        )
        JOIN Member m ON m.id = wl.memberId
    GROUP BY YEAR(tm.lastDate)
        , tm.month
        , m.groupId
)
, GroupDetails AS (
    SELECT g.id
        , g.name
        , COALESCE(gm.numberOfMember, 0) numberOfMember
    FROM GroupTarget g
        LEFT JOIN GroupMember gm ON gm.groupId = g.id
)
, RawSummary AS (
    SELECT g.id
        , g.name
        , YEAR(tm.lastDate) year
        , tm.month
        , g.numberOfMember
        , COALESCE(ws.numberOfWaitlisted, 0) numberOfWaitlisted
        , g.numberOfMember - COALESCE(ws.numberOfWaitlisted, 0) effectiveNumberOfMember
        , FLOOR((g.numberOfMember - COALESCE(ws.numberOfWaitlisted, 0)) * 0.8) target
        , COALESCE(ps.numberOfPayor, 0) numberOfPayor
        , COALESCE(ps.amount, 0) amount
    FROM GroupDetails as g
        CROSS JOIN TargetMonths tm
        LEFT JOIN WhitelistedSummary ws ON (
            ws.groupId = g.id
            AND YEAR(tm.lastDate) = ws.year
            AND tm.month = ws.month
        )
        LEFT JOIN PaymentSummary ps ON (
            ps.groupId = g.id
            AND ps.year = YEAR(tm.lastDate)
            AND ps.month = tm.month
        )
    ORDER BY g.name, YEAR(tm.lastDate), tm.month
)
SELECT rs.*
    , ROUND((numberOfPayor / effectiveNumberOfMember) * 100, 2) Overall
    , ROUND((LEAST(numberOfPayor, target) / target) * 100, 2) TargetAchievement
FROM RawSummary as rs
", [
    $tenantId,
    $year,
    $month,
    'active'
]);
    }

    public function getPayments($filter)
    {
        $fundsAccountRepository = new FundsAccountRepository();
        $depositFundsAccountId = $fundsAccountRepository->getDepositFundsAccountId($filter['tenantId']);
        $fundAccounts = $fundsAccountRepository->getFundsAccounts($filter)->pluck('name', 'id');
        $groups = Group::query()->where('tenantId', $filter['tenantId'])->get()->pluck('name', 'id');

        return Payment::query()
            ->from('Payment as p')
            ->join('PaymentBreakdown as pb', 'p.id', '=', 'pb.paymentId')
            ->join('Member as m', 'm.id', '=', 'p.memberId')
            ->when($filter['tenantId'], function ($query) use ($filter) {
                $query->where('p.tenantId', $filter['tenantId']);
            })
            ->when($filter['betweenDate'], function ($query) use ($filter) {
                $query->whereBetween('p.date', $filter['betweenDate']);
            })
            ->select('p.date', 'm.name as memberName', 'm.houseNumber', 'm.groupId', 'pb.fundsAccountId', 'pb.year', 'pb.month', 'pb.amount', 'p.code', 'p.id', 'p.notes', 'pb.memberId as payorId')
            ->get()->map(function($payment) use ($fundAccounts, $depositFundsAccountId) {
                $fundsAccountId = $payment->fundsAccountId;
                $type = 'income';
                if ($payment->year > $payment->date->year) {
                    $type = 'deposit';
                    $fundsAccountId = $depositFundsAccountId;
                } else if ($payment->date->year == $payment->year and $payment->month > $payment->date->month) {
                    $type = 'deposit';
                    $fundsAccountId = $depositFundsAccountId;
                }

                return [
                    ...$payment->toArray(),
                    'id' => $type . '-' . $payment->id,
                    'date' => Carbon::parse($payment->date)->toDateString(),
                    'fundsAccountName' => $fundAccounts[$fundsAccountId],
                ];
            })->groupBy('id')->map(function($payments) use ($groups) {
                $firstPayment = $payments->first();
                $fundAccountsNames = $payments->map(fn($payment) => $payment['fundsAccountName'])->unique();
                $fundAccountslabel = 'Semua pos';
                if ($fundAccountsNames->count() === 1) {
                    $fundAccountslabel = $fundAccountsNames->first();
                }
                $description = '';

                $periods = $payments->map(fn($payment) => Carbon::createFromDate((int) $payment['year'], (int) $payment['month'], 1)->format('M Y'))
                    ->unique()
                    ->values()
                    ->implode(', ');

                $memberCount = $payments->map(fn($payment) => $payment['payorId'])
                    ->unique()
                    ->values()
                    ->count();

                $description = 'Iuran ' . $periods;
                if ((int) $firstPayment['code'] === PaymentCode::COLLECTIVE_PAYMENT) {
                    $description = ($firstPayment['notes'] ?: 'Pembayaran kolektif') . ' - ' . $memberCount . ' KK: ' . $periods;
                } elseif ((int) $firstPayment['code'] === PaymentCode::DONATION) {
                    $description =  $firstPayment['notes'] ?: 'Donasi / sponsor';
                }

                return [
                    'date' => $firstPayment['date'],
                    'memberName' => $firstPayment['memberName'],
                    'houseNumber' => $firstPayment['houseNumber'],
                    'description' => $description,
                    'groupName' => $groups[$firstPayment['groupId']],
                    'fundsAccountName' => $fundAccountslabel,
                    'amount' => $payments->sum('amount'),
                ];
            })->values();
    }

    public function getMonthlyIncomePerGroup($tenantId, $year, $month)
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        $fundsAccountRepository = new FundsAccountRepository();
        $depositFundsAccountId = $fundsAccountRepository->getDepositFundsAccountId($tenantId);

        return Payment::query()
            ->from('Payment as p')
            ->join('PaymentBreakdown as pb', 'p.id', '=', 'pb.paymentId')
            ->join('Member as m', 'pb.memberId', '=', 'm.id')
            ->join('Group as g', 'm.groupId', '=', 'g.id')
            ->where('p.tenantId', $tenantId)
            ->where(function ($q) use ($monthStart, $monthEnd, $depositFundsAccountId) {
                $q->whereBetween('p.date', [$monthStart, $monthEnd])
                    ->orWhere(function($q) use($monthStart, $depositFundsAccountId) {
                        $q->where('pb.year', $monthStart->year)
                            ->where('pb.month', $monthStart->month)
                            ->where('p.date', '<', $monthStart)
                            ->where('pb.fundsAccountId', '!=', $depositFundsAccountId);
                    });
            })
            ->select('g.id as groupId', 'g.name as groupName', 'pb.year', 'pb.month', 'p.date as paidDate')
            ->selectRaw('SUM(pb.amount) as amount')
            ->selectRaw('COUNT(DISTINCT pb.memberId) AS numberOfMember')
            ->groupBy('g.id')
            ->groupBy('g.name')
            ->groupBy('pb.year')
            ->groupBy('pb.month')
            ->groupBy('p.date')
            ->get()
            ->map(function($payment) use ($monthStart) {
                $paymentPeriod = Carbon::create($payment->year, $payment->month, 1)->startOfDay();

                if ($paymentPeriod < $monthStart) {
                    $groupKey = 'owe';
                } elseif ($paymentPeriod > $monthStart) {
                    $groupKey = 'deposit';
                } elseif ($payment->paidDate < $paymentPeriod) {
                    $groupKey = 'fromdeposit';
                } else {
                    $groupKey = $paymentPeriod->format('M');
                }

                return [
                    ...$payment->toArray(),
                    'groupKey' => $groupKey,
                    'groupName' => $groupKey == 'fromdeposit' ? 'Simpanan Deposit' : $payment->groupName,
                    'groupId' => $groupKey == 'fromdeposit' ? '000' : $payment->groupId,
                ];
            })
            ->groupBy('groupName')
            ->map(function($group, $groupName) {
                return [
                    'groupId' => $group->first()['groupId'],
                    'groupName' => $groupName,
                    'amount' => $group->sum('amount'),
                    'details' => $group->groupBy('groupKey')->map(fn ($periodGroup) => $periodGroup->sum('amount')),
                ];
            })->values();
    }

    private function getTransferFromUntil($tenantId, $date, $fundsAccountId = null)
    {
        return FundsTransfer::query()
            ->from('FundsTransfer as ft')
            ->where('ft.tenantId', $tenantId)
            ->where('ft.date', '<=', $date)
            ->when($fundsAccountId, function ($query) use ($fundsAccountId) {
                $query->where('ft.fromFundsAccountId', $fundsAccountId);
            })
            ->select('ft.fromFundsAccountId as fundsAccountId')
            ->selectRaw('SUM(ft.amount) as amount')
            ->groupBy('ft.fromFundsAccountId')
            ->get()->pluck('amount', 'fundsAccountId');
    }
}
