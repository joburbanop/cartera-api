<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use App\Services\Financial\Transaction\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

it('maps selected installments and payment option into the regular payment DTO', function () {
    $request = Mockery::mock(StoreTransactionRequest::class);

    $request->shouldReceive('input')->with('selected_installments', Mockery::any())->andReturn([3, 4]);
    $request->shouldReceive('input')->with('installment_numbers', Mockery::any())->andReturn([]);
    // fromRequest evalúa el default: input('payment_date', input('transaction_date'))
    $request->shouldReceive('input')->with('transaction_date')->andReturn('2026-08-24');
    $request->shouldReceive('input')->with('transaction_date', Mockery::any())->andReturn('2026-08-24');
    $request->shouldReceive('input')->with('payment_date', Mockery::any())->andReturn(null);
    $request->shouldReceive('input')->with('transaction_type', Mockery::any())->andReturn('regular_payment');
    $request->shouldReceive('input')->with('transactionType', Mockery::any())->andReturn(null);
    $request->shouldReceive('validated')->andReturnUsing(function (?string $key = null, mixed $default = null) {
        return match ($key) {
            'amount' => '14114428.60',
            'payment_method' => 'transfer',
            'payment_option' => 'reducir_plazo',
            'surplus_action' => null,
            'recalculation_type' => 'reducir_plazo',
            'receipt_number' => null,
            'bank_account_id' => null,
            default => $default,
        };
    });
    $request->shouldReceive('file')->with('receipt')->andReturn(UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'));

    $dto = CreateTransactionDTO::fromRequest($request, 42);

    expect($dto->contractId)->toBe(42)
        ->and($dto->transactionType)->toBe(TransactionType::REGULAR_PAYMENT)
        ->and($dto->installmentNumbers)->toBe([3, 4])
        ->and($dto->paymentOption)->toBe('reducir_plazo')
        ->and($dto->bankAccountId)->toBeNull();
});

it('TransactionService rechaza register de regular_payment y pide usar /collections/cascade', function () {
    try {
        app(TransactionService::class)->register(new CreateTransactionDTO(
            contractId: 1,
            amount: '1000.00',
            transactionDate: Carbon::parse('2026-09-10'),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::REGULAR_PAYMENT,
            installmentNumbers: [1],
        ));
        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect($e->errors()['transaction_type'][0] ?? '')->toBe(TransactionService::REGULAR_PAYMENT_USE_CASCADE);
    }
});

it('TransactionService rechaza register de extraordinary_payment y pide usar /collections/cascade', function () {
    try {
        app(TransactionService::class)->register(new CreateTransactionDTO(
            contractId: 1,
            amount: '1000.00',
            transactionDate: Carbon::parse('2026-09-10'),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::EXTRAORDINARY_PAYMENT,
            installmentNumbers: [1],
            paymentOption: 'reducir_plazo',
        ));
        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect($e->errors()['transaction_type'][0] ?? '')->toBe(TransactionService::EXTRAORDINARY_PAYMENT_USE_CASCADE);
    }
});

it('TransactionService rechaza register de refund', function () {
    try {
        app(TransactionService::class)->register(new CreateTransactionDTO(
            contractId: 1,
            amount: '1000.00',
            transactionDate: Carbon::parse('2026-09-10'),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::REFUND,
            installmentNumbers: [],
        ));
        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect($e->errors()['transaction_type'][0] ?? '')->toBe(TransactionService::REFUND_NOT_ACCEPTED);
    }
});
