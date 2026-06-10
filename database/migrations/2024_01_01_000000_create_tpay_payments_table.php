<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained($this->prefix.'orders')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained($this->prefix.'transactions')->nullOnDelete();
            $table->string('tpay_transaction_id', 64)->unique();
            $table->string('external_id', 36)->index();
            $table->string('status', 20);
            $table->unsignedInteger('amount');
            $table->char('currency', 3)->default('PLN');
            $table->string('redirect_url', 3072)->nullable();
            $table->foreignId('parent_payment_id')->nullable()->constrained('tpay_payments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpay_payments');
    }
};
