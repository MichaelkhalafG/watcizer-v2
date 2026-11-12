<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * MyFatoorah's payment id is a STRING (Block B, 2026-11-12). `payment_statuses.pay_transaction_id` was a BIGINT because
 * Paymob's transaction ids are numbers; MyFatoorah's `PaymentId` is a long digit string (wider than BIGINT's 19 digits)
 * and is the one id that names one payment ATTEMPT — the idempotency key of the callback route (the UNIQUE
 * (provider, pay_transaction_id) index, which this keeps). Every reader already treats the column as a string.
 *
 * Reversible while every stored value is still a number: down() refuses otherwise, rather than truncating ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_statuses MODIFY pay_transaction_id VARCHAR(64) NULL');
    }

    public function down(): void
    {
        $text = DB::table('payment_statuses')->whereNotNull('pay_transaction_id')
            ->whereRaw("pay_transaction_id NOT REGEXP '^-?[0-9]{1,18}$'")->count();
        if ($text > 0) {
            throw new RuntimeException("{$text} payment attempt(s) carry a transaction id that does not fit a BIGINT (MyFatoorah): the column cannot go back without losing them.");
        }
        DB::statement('ALTER TABLE payment_statuses MODIFY pay_transaction_id BIGINT NULL');
    }
};
