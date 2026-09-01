<?php

namespace App\Repositories;

use App\Models\ExpenseRepository;
use App\Models\ExpenseSourceOfFunds;
use App\Models\PaymentBreakdown;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentAux;
use App\Models\PaymentMember;
use App\Models\FundsTransfer;
use App\Models\FundsAccount;
use App\Models\Group;
use Carbon\Carbon;
use App\Repositories\FundsAccountRepository;
use App\Repositories\SettingRepository;
use App\Support\PaymentCode;
use App\Support\Constants;
use Illuminate\Support\Facades\DB;
use App\Support\Roles;

class PaymentRepository
{
    public function getOverdue($tenantId, $groupId = null)
    {
        $settingRepository = new SettingRepository();
        $firstPaymentDate = $settingRepository->getSetting($tenantId, Constants::SETTING_PAYMENT_COLLECTION_STARTDATE);

        $filterGroup = '';
        if (!empty($groupId)) {
            $filterGroup = "AND g.id = '$groupId'";
        }

        return DB::select("
WITH RECURSIVE months AS (
    SELECT DATE_FORMAT(?, '%Y-%m-01') AS month_date

    UNION ALL
    SELECT DATE_ADD(month_date, INTERVAL 1 MONTH)
    FROM months
    WHERE month_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')
)
, FilterValues AS (
    SELECT ? tenantId
)
, GroupTarget AS (
    SELECT g.id
        , g.name
    FROM `Group` g
        JOIN FilterValues fv ON fv.tenantId = g.tenantId
    WHERE 1=1
        $filterGroup
)
, TargetMonths AS (
    SELECT
        YEAR(month_date) AS year
        , MONTH(month_date) AS month
        , LAST_DAY(month_date) lastDate
    FROM months
    WHERE LAST_DAY(month_date) < LAST_DAY(CURDATE())
)
, Payments AS (
    SELECT pm.memberId
        , pm.year
        , pm.month
    FROM PaymentMember pm
        JOIN FilterValues fv ON (fv.tenantId = pm.tenantId)
        JOIN TargetMonths tm ON (tm.year = pm.year AND tm.month = pm.month)
)
, Members AS (
    SELECT m.*
        , g.name as groupName
    FROM Member m
        JOIN GroupTarget g ON g.id = m.groupId
    WHERE m.status = ?
)
SELECT m.id
    , m.name
    , m.houseNumber
    , m.groupName
    -- , m.id numberOfOverduePeriods
    , lastDate overduePeriods
FROM Members m
    CROSS JOIN TargetMonths tm
    LEFT JOIN Payments p ON (
        p.memberId = m.id
        AND p.year = tm.year
        AND p.month = tm.month
    )
WHERE p.memberId IS NULL
ORDER BY m.groupName
    , m.houseNumber", [
            $firstPaymentDate->format('Y-m-d'),
            $tenantId,
            'active'
        ]);
    }

    public function getPaymentSettled($tenantId, $groupId, $year, $month)
    {
        return Collect(DB::select("
WITH Members AS (
    SELECT * FROM Member
    WHERE tenantId = ?
        AND groupId = ?
)
, Payments AS (
    SELECT m.id
        , pm.year
        , pm.month
        , sum(pm.amount) amount
    FROM PaymentMember pm
        JOIN Members m ON m.id = pm.memberId
    WHERE pm.year = ?
        AND pm.month = ?
    GROUP BY m.id
        , pm.year
        , pm.month
)
SELECT m.id
    , m.name
    , m.houseNumber
    , m.status
    , p.amount
    , p.year
    , p.month
FROM Members m
    LEFT JOIN Payments p ON p.id = m.id
",
        [
            $tenantId,
            $groupId,
            $year,
            $month
        ]))->map(function ($item) {
            $item->periods = '';
            if (!empty($item->amount)) {
                $item->periods = Carbon::createFromDate((int) $item->year, (int) $item->month, 1)->format('M Y');
            }

            return $item;
        });
    }

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
, PaymentSummary AS (
    SELECT pa.groupId
        , pa.year
        , pa.month
        , sum(pa.totalMember) numberOfPayor
        , sum(p.amount) amount
        , sum(pa.incentiveAmount) incentiveAmount
    FROM Payment p
        JOIN PaymentAux pa ON pa.paymentId = p.id
        JOIN FilterValues fv ON (
            fv.year = pa.year
            AND (fv.month = 0 OR fv.month = pa.month)
            AND fv.tenantId = pa.tenantId
        )
    GROUP BY pa.groupId
        , pa.year
        , pa.month
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
            ->join('PaymentAux as pa', 'p.id', '=', 'pa.paymentId')
            ->join('PaymentBreakdown as pb', 'p.id', '=', 'pb.paymentId')
            ->join('Member as m', 'm.id', '=', 'p.memberId')
            ->when($filter['tenantId'], function ($query) use ($filter) {
                $query->where('p.tenantId', $filter['tenantId']);
            })
            ->when($filter['betweenDate'], function ($query) use ($filter) {
                $query->whereBetween('p.date', $filter['betweenDate']);
            })
            ->select('p.date', 'm.name as memberName', 'm.houseNumber', 'pa.groupId', 'pb.fundsAccountId', 'pa.year', 'pa.month', 'pb.amount', 'p.code', 'p.id', 'p.notes', 'pa.totalMember')
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

                $memberCount = $payments->first()['totalMember'];

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
        $monthStart = $monthEnd =null;
        if (!empty($year) && !empty($month)) {
            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
        }

        $fundsAccountRepository = new FundsAccountRepository();
        $depositFundsAccountId = $fundsAccountRepository->getDepositFundsAccountId($tenantId);

        return Payment::query()
            ->from('Payment as p')
            ->join('PaymentAux as pa', 'p.id', '=', 'pa.paymentId')
            ->join('PaymentBreakdown as pb', 'p.id', '=', 'pb.paymentId')
            ->join('Group as g', 'pa.groupId', '=', 'g.id')
            ->where('p.tenantId', $tenantId)
            ->when(!empty($monthStart) && !empty($monthEnd), function ($query) use ($monthStart, $monthEnd, $depositFundsAccountId) {
                $query->where(function ($q) use ($monthStart, $monthEnd, $depositFundsAccountId) {
                    $q->whereBetween('p.date', [$monthStart, $monthEnd])
                        ->orWhere(function($q) use($monthStart, $depositFundsAccountId) {
                            $q->where('pa.year', $monthStart->year)
                                ->where('pa.month', $monthStart->month)
                                ->where('p.date', '<', $monthStart)
                                ->where('pb.fundsAccountId', '!=', $depositFundsAccountId);
                        });
                });
            })
            ->select('g.id as groupId', 'g.name as groupName', 'pa.year', 'pa.month', 'p.date as paidDate', 'p.code')
            ->selectRaw('SUM(pb.amount) as amount')
            ->selectRaw('SUM(pa.totalMember) AS numberOfMember')
            ->groupBy('g.id')
            ->groupBy('g.name')
            ->groupBy('pa.year')
            ->groupBy('pa.month')
            ->groupBy('p.date')
            ->groupBy('p.code')
            ->get()
            ->map(function($payment) use ($monthStart) {
                $paymentPeriod = Carbon::create($payment->year, $payment->month, 1)->startOfDay();
                $groupName = 'Blok ' . $payment->groupName;
                $groupId = $payment->groupId;

                if (empty($monthStart)) {
                    $groupName = $payment->groupName;
                    $groupKey = $paymentPeriod->format('M');
                } else if ($payment->code === PaymentCode::DONATION) {
                    $groupKey = 'other';
                    $groupName = 'Pendapatan Lain';
                    $groupId = '003';
                } elseif ($paymentPeriod < $monthStart) {
                    $groupKey = 'owe';
                    $groupName .= ' (Susulan Iuran ' .$paymentPeriod->format('M') . ')';
                    $groupId .= 'Owe' . $paymentPeriod->format('YM');
                } elseif ($paymentPeriod > $monthStart) {
                    $groupKey = 'deposit';
                    $groupName .= ' (Deposit)';
                    $groupId .= 'DEPO';
                } elseif ($payment->paidDate < $paymentPeriod) {
                    $groupKey = 'fromdeposit';
                    $groupName = 'Pencairan Deposit';
                    $groupId = '000';
                } else {
                    $groupKey = $paymentPeriod->format('M');
                }

                return [
                    ...$payment->toArray(),
                    'groupKey' => $groupKey,
                    'groupName' => $groupName,
                    'groupId' => $groupId,
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
            })->sortBy('groupName')->values();
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

    public function getPaymentMemberRecord($tenantId, $memberId, $year, $month)
    {
        return PaymentMember::query()
            ->where('tenantId', $tenantId)
            ->where('memberId', $memberId)
            ->where('year', $year)
            ->where('month', $month)
            ->get()->first();
    }

    public function getPaymentList($filter)
    {
        $binds = [
            $filter['tenantId']
        ];
        $where = [
            'p.tenantId = ?'
        ];

        if (!empty($filter['year'])) {
            $where[] = '(CASE WHEN p.code = ? OR p.code = ? THEN pa.year ELSE YEAR(p.date) END) = ?';
            $binds[] = PaymentCode::MONTHLY_PAYMENT;
            $binds[] = PaymentCode::COLLECTIVE_PAYMENT;
            $binds[] = $filter['year'];
        }

        if (!empty($filter['month'])) {
            $where[] = '(CASE WHEN p.code = ? OR p.code = ? THEN pa.month ELSE MONTH(p.date) END) = ?';
            $binds[] = PaymentCode::MONTHLY_PAYMENT;
            $binds[] = PaymentCode::COLLECTIVE_PAYMENT;
            $binds[] = $filter['month'];
        }

        if (!empty($filter['memberId'])) {
            $where[] = 'pa.memberId = ?';
            $binds[] = $filter['memberId'];
        }

        if (!empty($filter['groupId'])) {
            $where[] = 'pa.groupId = ?';
            $binds[] = $filter['groupId'];
        }

        $whereSQL = $where ? ' AND ' . implode(' AND ', $where) : '';

        $sql = <<<SQL
SELECT p.id
    , m.name payorName
    , m.houseNumber
    , g.name groupName
    , p.date
    , p.amount
    , p.code
    , pa.month
    , pa.year
    , pa.totalMember
    , pa.amountPerMember
    , pa.incentiveAmount
FROM Payment p
    JOIN Member m ON m.id = p.memberId
    LEFT JOIN PaymentAux pa ON pa.paymentId = p.id
    LEFT JOIN `Group` g ON g.id = pa.groupId
WHERE true
{$whereSQL}
GROUP BY p.id
    , m.name
    , m.houseNumber
    , g.name
    , p.date
    , p.amount
    , p.code
    , pa.month
    , pa.year
    , pa.totalMember
    , pa.amountPerMember
    , pa.incentiveAmount
ORDER BY p.date DESC
SQL;

        return DB::select($sql, $binds);
    }

    public function delete($user, $paymentId)
    {
        DB::transaction(function () use ($user, $paymentId) {
            $query = Payment::query()->where('id', $paymentId);
            if ($user->role !== Roles::SUPER_ADMIN) {
                $query->where('tenantId', $user->tenantId);
            }

            $payment = $query->first();
            if (!$payment) {
                return response()->json(['error' => 'Payment not found.'], 404);
            }

            PaymentAux::query()->where('paymentId', $paymentId)->delete();
            PaymentMember::query()->where('paymentId', $paymentId)->delete();
            PaymentBreakdown::query()->where('paymentId', $paymentId)->delete();
            $payment->delete();
        });
    }
}
