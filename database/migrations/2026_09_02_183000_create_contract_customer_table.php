<?php

use App\Enums\ContractCustomerRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_customer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['contract_id', 'customer_id']);
            $table->index(['customer_id', 'role']);
        });

        $now = now();

        DB::table('contracts')
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($now): void {
                foreach ($rows as $row) {
                    if (! DB::table('customers')->where('id', $row->customer_id)->exists()) {
                        continue;
                    }

                    DB::table('contract_customer')->insertOrIgnore([
                        'contract_id' => $row->id,
                        'customer_id' => $row->customer_id,
                        'role' => ContractCustomerRole::TITULAR_PRINCIPAL->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_customer');
    }
};
