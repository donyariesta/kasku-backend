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

class PaymentRepository
{
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
