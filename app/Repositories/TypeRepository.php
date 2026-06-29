<?php

namespace App\Repositories;

use App\Models\Type;

class TypeRepository
{
    public function getSystemTypeId($tenantId, $code)
    {
        return Type::query()
            ->where('tenantId', $tenantId)
            ->where('isSystem', true)
            ->where('code', $code)
            ->get()->first()->id;
    }
}
