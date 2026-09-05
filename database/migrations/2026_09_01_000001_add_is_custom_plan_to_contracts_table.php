<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_custom_plan')->default(false)->after('preventa_installments_count');
        });

        $ids = DB::table('contract_payment_promises')->distinct()->pluck('contract_id');

        if ($ids->isNotEmpty()) {
            DB::table('contracts')->whereIn('id', $ids)->update(['is_custom_plan' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('is_custom_plan');
        });
    }
};
