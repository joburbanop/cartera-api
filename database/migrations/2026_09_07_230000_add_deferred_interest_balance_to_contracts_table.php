<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo de interés corriente causado no pagado que, al refinanciar, el admin
 * decide cobrar aparte (nunca capitalizado: anatocismo prohibido).
 *
 * $2.600.440.231 es la línea base histórica de San Miguel; los pagos futuros
 * de este saldo (transaction_type = interes_diferido) suben el recaudo
 * de forma esperada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('deferred_interest_balance', 15, 2)
                ->default(0)
                ->after('down_payment_pactada');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('deferred_interest_balance');
        });
    }
};
