<?php

use App\Models\Tenant;
use App\Support\TypeCode;
use Database\Seeders\TypeSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => TypeSeeder::seedForTenant($tenant));
    }

    public function down(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            \App\Models\Type::query()
                ->where('tenantId', $tenant->id)
                ->where('isSystem', true)
                ->where('code', TypeCode::MEMBER_VACANT)
                ->delete();
        });
    }
};
