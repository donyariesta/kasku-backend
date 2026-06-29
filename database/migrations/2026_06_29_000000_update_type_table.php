<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Type', function (Blueprint $table): void {
            $table->boolean('isSystem')->default(false)->after('description');
            $table->integer('code')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('Type', function (Blueprint $table): void {
            if (Schema::hasColumn('Type', 'isSystem')) {
                $table->dropColumn('isSystem');
            }
            if (Schema::hasColumn('Type', 'code')) {
                $table->dropColumn('code');
            }
        });


    }
};
