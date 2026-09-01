<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PaymentMember', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->foreignUuid('memberId')->constrained('Member')->restrictOnDelete();
            $table->foreignUuid('paymentId')->constrained('Payment')->restrictOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->double('amount');
            $table->json('paymentBreakdown');
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });

        Schema::create('PaymentAux', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->foreignUuid('paymentId')->constrained('Payment')->restrictOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->foreignUuid('groupId')->constrained('Group')->restrictOnDelete();
            $table->double('incentiveAmount');
            $table->integer('totalMember');
            $table->integer('amountPerMember');
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });

        Schema::table('FundsAccount', function (Blueprint $table): void {
            $table->boolean('isDefault')->default(false)->after('isSystem');
        });

        DB::statement("
            INSERT INTO PaymentAux (id, tenantId, paymentId, month, year, groupId, incentiveAmount, totalMember, amountPerMember)

            SELECT UUID() id, p.tenantId, pb.paymentId, month, year, groupId, 0 incentiveAmount, count(distinct pb.memberId) totalMember, (p.amount / count(distinct pb.memberId)) amountPerMember
            FROM PaymentBreakdown pb
                JOIN Payment p ON p.id = pb.paymentId
                JOIN Member m ON m.id = pb.memberId
                JOIN `Group` g ON g.id = m.groupId
            GROUP BY tenantId, PaymentID, month, year, p.amount, groupId, g.name
        ");

        DB::statement("
            INSERT INTO PaymentMember (id, tenantId, paymentId, memberId, year, month, amount, paymentBreakdown)

            SELECT UUID(), p.tenantId, pb.paymentId, pb.memberId, pb.year, pb.month, SUM(pb.amount)
                , CONCAT('[', GROUP_CONCAT(JSON_OBJECT('id', pb.FundsAccountID, 'amount', pb.Amount)), ']') AS funds
            FROM PaymentBreakdown pb
                JOIN Payment p ON p.id = pb.paymentId
            GROUP BY tenantId, pb.paymentId, pb.memberId, pb.year, pb.month
        ");

        DB::statement("
            INSERT INTO PaymentBreakdown (id, paymentId, memberId, amount, fundsAccountId, month, year, notes)

            SELECT UUID(), paymentId, null, sum(amount), fundsAccountId, month, year, count(DISTINCT memberId)
            FROM PaymentBreakdown pb
            GROUP BY paymentId, fundsAccountId, month, year;
        ");
        DB::statement("
            DELETE FROM PaymentBreakdown WHERE memberId IS NOT NULL
        ");

        Schema::table('PaymentBreakdown', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('memberId');
            $table->dropColumn('month');
            $table->dropColumn('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PaymentMember');
        Schema::table('FundsAccount', function (Blueprint $table): void {
            if (Schema::hasColumn('FundsAccount', 'isDefault')) {
                $table->dropColumn('isDefault');
            }
        });
    }
};
