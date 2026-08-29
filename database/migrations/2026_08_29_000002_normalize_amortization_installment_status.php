<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('amortization_installments')) {
            return;
        }

        $map = [
            'sin_pagar' => 'pending',
            'pagada' => 'paid',
            'parcial' => 'partial',
            'vencida' => 'overdue',
        ];

        foreach ($map as $from => $to) {
            DB::table('amortization_installments')
                ->where('status', $from)
                ->update(['status' => $to]);
        }
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('amortization_installments')) {
            return;
        }

        $map = [
            'pending' => 'sin_pagar',
            'paid' => 'pagada',
            'partial' => 'parcial',
            'overdue' => 'vencida',
        ];

        foreach ($map as $from => $to) {
            DB::table('amortization_installments')
                ->where('status', $from)
                ->update(['status' => $to]);
        }
    }
};
