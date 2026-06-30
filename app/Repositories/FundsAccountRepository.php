<?php

namespace App\Repositories;

use App\Models\ExpenseRepository;
use App\Models\ExpenseSourceOfFunds;
use App\Models\PaymentBreakdown;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\FundsTransfer;
use App\Models\FundsAccount;

class FundsAccountRepository
{
    public function getFundsAccounts($filter)
    {
        return FundsAccount::query()
            ->when($filter['tenantId'], function ($query) use ($filter) {
                $query->where('tenantId', $filter['tenantId']);
            })->get();
    }

    public function getBalanceUntil($tenantId, $date, $fundsAccountId = null)
    {
        $expenses = $this->getExpenseUntil($tenantId, $date, $fundsAccountId);
        $payments = $this->getPaymentUntil($tenantId, $date, $fundsAccountId);
        $transferTo = $this->getTransferToUntil($tenantId, $date, $fundsAccountId);
        $transferFrom = $this->getTransferFromUntil($tenantId, $date, $fundsAccountId);

        return FundsAccount::query()
            ->where('tenantId', $tenantId)
            ->when($fundsAccountId, function ($query) use ($fundsAccountId) {
                $query->where('id', $fundsAccountId);
            })
            ->get()
            ->map(function (FundsAccount $account) use ($expenses, $payments, $transferTo, $transferFrom, $date) {
                $accountId = $account->id;
                $expenseAmount = $expenses->get($accountId, 0);
                $paymentAmount = $payments->get($accountId, 0);
                $transferToAmount = $transferTo->get($accountId, 0);
                $transferFromAmount = $transferFrom->get($accountId, 0);

                return [
                    'isDeposit' => $account->isDeposit(),
                    'asOfDate' => $date,
                    'active' => $account->active,
                    'fundsAccountId' => $accountId,
                    'fundsAccountName' => $account->name,
                    'expenseAmount' => $expenseAmount,
                    'paymentAmount' => $paymentAmount,
                    'transferToAmount' => $transferToAmount,
                    'transferFromAmount' => $transferFromAmount,
                    'balance' => ($paymentAmount + $transferToAmount) - ($expenseAmount + $transferFromAmount),
                ];
            });
    }

    private function getExpenseUntil($tenantId, $date, $fundsAccountId = null)
    {
        return ExpenseSourceOfFunds::query()
            ->from('ExpenseSourceOfFunds as es')
            ->join('Expense as e', 'e.id', '=', 'es.expenseId')
            ->where('e.tenantId', $tenantId)
            ->where('e.date', '<=', $date)
            ->when($fundsAccountId, function ($query) use ($fundsAccountId) {
                $query->where('es.fundsAccountId', $fundsAccountId);
            })
            ->select('es.fundsAccountId')
            ->selectRaw('SUM(es.amount) as amount')
            ->groupBy('es.fundsAccountId')
            ->get()->pluck('amount', 'fundsAccountId');
    }

    public function getDepositFundsAccountId($tenantId)
    {
        return FundsAccount::query()
            ->select('id')
            ->where('tenantId', $tenantId)
            ->where('isSystem', true)
            ->where('name', FundsAccount::DEPOSIT_NAME)
            ->get()->first()->id;
    }

    private function getPaymentUntil($tenantId, $date, $fundsAccountId = null)
    {
        $depositFundsAccountId = $this->getDepositFundsAccountId($tenantId);

        return PaymentBreakdown::query()
            ->from('PaymentBreakdown as pb')
            ->join('Payment as p', 'p.id', '=', 'pb.paymentId')
            ->where('p.tenantId', $tenantId)
            ->where('p.date', '<=', $date)
            ->when($fundsAccountId, function ($query) use ($fundsAccountId) {
                $query->where('pb.fundsAccountId', $fundsAccountId);
            })
            ->select('pb.fundsAccountId', 'pb.year', 'pb.month')
            ->selectRaw('SUM(pb.amount) as amount')
            ->groupBy('pb.fundsAccountId', 'pb.year', 'pb.month')
            ->get()->map(function($payment) use ($date, $depositFundsAccountId) {
                if ($payment->year > $date->year) {
                    $fundsAccountId = $depositFundsAccountId;
                } else if ($payment->year == $date->year and $payment->month > $date->month) {
                    $fundsAccountId = $depositFundsAccountId;
                } else {
                    $fundsAccountId = $payment->fundsAccountId;
                }

                return [
                    'fundsAccountId' => $fundsAccountId,
                    'amount' => $payment->amount,
                ];
            })
            ->groupBy('fundsAccountId')
            ->map(function($payments, $fundsAccountId) {
                return [
                    'fundsAccountId' => $fundsAccountId,
                    'amount' => $payments->sum('amount'),
                ];
            })
            ->pluck('amount', 'fundsAccountId');
    }

    private function getTransferToUntil($tenantId, $date, $fundsAccountId = null)
    {
        return FundsTransfer::query()
            ->from('FundsTransfer as ft')
            ->where('ft.tenantId', $tenantId)
            ->where('ft.date', '<=', $date)
            ->when($fundsAccountId, function ($query) use ($fundsAccountId) {
                $query->where('ft.toFundsAccountId', $fundsAccountId);
            })
            ->select('ft.toFundsAccountId as fundsAccountId')
            ->selectRaw('SUM(ft.amount) as amount')
            ->groupBy('ft.toFundsAccountId')
            ->get()->pluck('amount', 'fundsAccountId');
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
