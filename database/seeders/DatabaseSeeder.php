<?php

namespace Database\Seeders;

use App\Models\Type;
use App\Models\User;
use App\Models\Tenant;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = Hash::make('admin123');
        $memberPassword = Hash::make('member123');

        $tenant = Tenant::firstOrCreate([
            'slug' => 'default',
        ], [
            'name' => 'Default Community',
        ]);

        User::updateOrCreate([
            'username' => 'superadmin',
        ], [
            'password' => $adminPassword,
            'name' => 'System Super Admin',
            'role' => Roles::SUPER_ADMIN,
            'tenantId' => null,
        ]);

        User::updateOrCreate([
            'username' => 'admin',
        ], [
            'password' => $adminPassword,
            'name' => 'Tenant Admin',
            'role' => Roles::TENANT_ADMIN,
            'tenantId' => $tenant->id,
        ]);

        User::updateOrCreate([
            'username' => 'member',
        ], [
            'password' => $memberPassword,
            'name' => 'Community Member',
            'role' => Roles::MEMBER,
            'tenantId' => $tenant->id,
        ]);

        $this->call(FundsAccountSeeder::class);

        $expenseTypes = [
            ['type' => 'maintenance', 'description' => 'Expenses type maintenance'],
            ['type' => 'security', 'description' => 'Expenses type security'],
            ['type' => 'social', 'description' => 'Expenses type social activities'],
            ['type' => 'utilities', 'description' => 'Expenses type utilities'],
            ['type' => 'other', 'description' => 'Expenses type others'],
        ];

        foreach ($expenseTypes as $item) {
            Type::updateOrCreate(
                [
                    'tenantId' => $tenant->id,
                    'group' => 'expenses',
                    'type' => $item['type'],
                ],
                ['description' => $item['description']]
            );
        }
    }
}
