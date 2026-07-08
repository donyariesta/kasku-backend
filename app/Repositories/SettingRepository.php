<?php

namespace App\Repositories;

use App\Models\Setting;
use App\Support\Constants;

class SettingRepository
{
    public function getSetting($tenantId, $fieldId)
    {
        $setting = Setting::query()
            ->where('tenantId', $tenantId)
            ->where('fieldId', $fieldId)
            ->first();

        if (!$setting) {
            return null;
        }

        $valueColumn = $this->getValueColumn($fieldId);

        return $setting->$valueColumn;
    }

    private function getValueColumn($fieldId)
    {
        $mapping = [
            Constants::SETTING_PAYMENT_COLLECTION_STARTDATE => 'dateValue',
        ];

        return $mapping[$fieldId] ?? 'stringValue';
    }
}
