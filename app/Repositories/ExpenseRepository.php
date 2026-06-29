<?php

namespace App\Repositories;

use App\Support\TypeCode;
use App\Models\Expense;
use Carbon\Carbon;

class ExpenseRepository
{
    public function getExpensesTypeOnMonth($tenantId, $year, $month)
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        return Expense::query()
            ->from('Expense as e')
            ->join('Type as t', 't.id', '=', 'e.typeId')
            ->where('e.tenantId', $tenantId)
            ->whereBetween('e.date', [$monthStart, $monthEnd])
            ->select('t.type')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('t.type')
            ->get();
    }

    public function getExpenses(array $filter): Array
    {
        $query = Expense::query()
          ->with('expenseSourceOfFunds')
          ->with('member')
          ->with('type')
          ->orderByDesc('date');

        if ($filter['tenantId']) {
            $query->where('tenantId', $filter['tenantId']);
        }
        if (!empty($filter['betweenDate'])) {
            $query->whereBetween('date', $filter['betweenDate']);
        }

        $expenses = $query->get();

        return $expenses->map(function (Expense $expense) {
            return [
                'id' => $expense->id,
                'title' => $expense->title,
                'amount' => $expense->amount,
                'date' => $expense->date,
                'description' => $expense->description,
                'sourceOfFunds' => $expense->expenseSourceOfFunds->map(function ($source) {
                    return [
                      'id' => $source->fundsAccount->id ?? null,
                      'amount' => $source->amount ?? null,
                      'name' => $source->fundsAccount->name ?? null
                    ];
                })->all(),
                'sourceOfFundsCompacted' => (($expense->type->code ?? 0) === TypeCode::EXPENSE_COLLECTION_INCENTIVE) ? 'Semua Pos' : implode(', ', $expense->expenseSourceOfFunds->map(function ($source) {
                    return $source->fundsAccount->name ?? null;
                })->all()),
                'issuer' => $expense->member->name ?? null,
                'type' => $expense->type->type ?? null,
                'typeDescription' => $expense->type->description ?? null,
                'status' => $expense->status ?? '-',
            ];
        })->values()->all();
    }
}
