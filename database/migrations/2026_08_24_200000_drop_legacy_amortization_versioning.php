<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('amortization_installments')) {
            return;
        }

        if (Schema::hasColumn('amortization_installments', 'amortization_version_id')) {
            DB::statement('ALTER TABLE amortization_installments DROP CONSTRAINT IF EXISTS amortization_installments_amortization_version_id_foreign');
            Schema::table('amortization_installments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('amortization_version_id');
            });
        }

        if (Schema::hasTable('amortization_versions')) {
            Schema::dropIfExists('amortization_versions');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('amortization_installments')) {
            return;
        }

        if (! Schema::hasTable('amortization_versions')) {
            Schema::create('amortization_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');
                $table->integer('version_number')->default(1);
                $table->boolean('is_active')->default(true);
                $table->string('recalculation_type')->default('initial_projection');
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('amortization_installments', 'amortization_version_id')) {
            Schema::table('amortization_installments', function (Blueprint $table) {
                $table->foreignId('amortization_version_id')->nullable()->constrained('amortization_versions')->onDelete('cascade');
            });
        }
    }
};
