<?php

namespace App\Repositories;

use App\Models\Member;

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
}
