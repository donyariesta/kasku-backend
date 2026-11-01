<?php

namespace App\Repositories;

use App\Models\Member;
use App\Support\TypeCode;
use Carbon\Carbon;

class GroupRepository
{
    public function getGroupNameByMemberIds($memberIds)
    {
        return Member::query()
            ->select('g.name')
            ->from('Member as m')
            ->join('Group as g', 'g.id', '=', 'm.groupId')
            ->whereIn('m.id', $memberIds)
            ->get()->pluck('name')->unique();
    }

    public function getMonthlyPayments($values)
    {
        $tenantId = $values['tenantId'];
        $groupId = $values['groupId'];
        $year = $values['year'];
        $month = $values['month'];
        $endPeriodDate = Carbon::createFromDate((int) $values['year'], (int) $values['month'], 1)->endOfMonth()->toDateString();

        $sql = <<<SQL
SELECT m.id "memberId"
    , m.name "name"
    , m.houseNumber "houseNumber"
    , m.status "status"
    , EXISTS (
        SELECT 1 FROM Whitelisted wl
            JOIN Type t ON t.id = wl.typeId
        WHERE wl.memberId = m.id
            AND wl.dateFrom <= :endPeriodDate
            AND (wl.dateTo IS NULL OR wl.dateTo >= :endPeriodDate2)
            AND NOT (t.isSystem = 1 AND t.code = :vacantCode)
    ) "whitelisted"
    , EXISTS (
        SELECT 1 FROM Whitelisted wl
            JOIN Type t ON t.id = wl.typeId
        WHERE wl.memberId = m.id
            AND wl.dateFrom <= :endPeriodDate3
            AND (wl.dateTo IS NULL OR wl.dateTo >= :endPeriodDate4)
            AND t.isSystem = 1
            AND t.code = :vacantCode2
    ) "isVacant"
    , pm.amount "amountPaid"
    , DATE(p.date) "paidDate"
    , pm.paymentId "paymentId"
FROM Member m
    LEFT JOIN PaymentMember pm ON (
        pm.memberId = m.id
        AND pm.year = :year
        AND pm.month = :month
    )
    LEFT JOIN Payment p ON p.id = pm.paymentId
WHERE m.tenantId = :tenantId
    AND m.groupId = :groupId
ORDER BY m.houseNumber, m.name
SQL;

        return \DB::select($sql, [
            'endPeriodDate' => $endPeriodDate,
            'endPeriodDate2' => $endPeriodDate,
            'endPeriodDate3' => $endPeriodDate,
            'endPeriodDate4' => $endPeriodDate,
            'vacantCode'     => TypeCode::MEMBER_VACANT,
            'vacantCode2'    => TypeCode::MEMBER_VACANT,
            'tenantId'       => $tenantId,
            'groupId'        => $groupId,
            'year'           => $year,
            'month'          => $month,
        ]);
    }

}
