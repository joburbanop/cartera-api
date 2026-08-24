<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use Illuminate\Http\UploadedFile;

it('maps selected installments and payment option into the regular payment DTO', function () {
    $request = Mockery::mock(StoreTransactionRequest::class);

    $request->shouldReceive('input')->with('selected_installments', Mockery::any())->andReturn([3, 4]);
    $request->shouldReceive('input')->with('installment_numbers', Mockery::any())->andReturn([]);
    $request->shouldReceive('input')->with('transaction_date', Mockery::any())->andReturn('2026-08-24');
    $request->shouldReceive('input')->with('payment_date', Mockery::any())->andReturn(null);
    $request->shouldReceive('input')->with('transaction_type', Mockery::any())->andReturn('regular_payment');
    $request->shouldReceive('input')->with('transactionType', Mockery::any())->andReturn(null);
    $request->shouldReceive('validated')->with('amount')->andReturn('14114428.60');
    $request->shouldReceive('validated')->with('payment_method')->andReturn('transfer');
    $request->shouldReceive('validated')->with('payment_option', Mockery::any())->andReturn('reducir_plazo');
    $request->shouldReceive('validated')->with('surplus_action', Mockery::any())->andReturn(null);
    $request->shouldReceive('file')->with('receipt')->andReturn(UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'));

    $dto = CreateTransactionDTO::fromRequest($request, 42);

    expect($dto->contractId)->toBe(42)
        ->and($dto->transactionType)->toBe(TransactionType::REGULAR_PAYMENT)
        ->and($dto->installmentNumbers)->toBe([3, 4])
        ->and($dto->paymentOption)->toBe('reducir_plazo');
});
