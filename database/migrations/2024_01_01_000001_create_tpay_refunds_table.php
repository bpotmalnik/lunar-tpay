<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpay_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tpay_payment_id')->constrained('tpay_payments')->cascadeOnDelete();
            $table->foreignId('lunar_transaction_id')->nullable()->constrained($this->prefix.'transactions')->nullOnDelete();
            $table->string('refund_id', 64)->unique();
            $table->string('status', 20);
            $table->unsignedInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpay_refunds');
    }
};
