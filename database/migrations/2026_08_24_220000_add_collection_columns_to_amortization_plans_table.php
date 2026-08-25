<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('amortization_plans', 'status')) {
                $table->string('status', 50)->default('pending')->after('quota_debt');
            }

            if (! Schema::hasColumn('amortization_plans', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('status');
            }

            if (! Schema::hasColumn('amortization_plans', 'balance_due')) {
                $table->decimal('balance_due', 15, 2)->default(0)->after('paid_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            if (Schema::hasColumn('amortization_plans', 'balance_due')) {
                $table->dropColumn('balance_due');
            }

            if (Schema::hasColumn('amortization_plans', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }

            if (Schema::hasColumn('amortization_plans', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
