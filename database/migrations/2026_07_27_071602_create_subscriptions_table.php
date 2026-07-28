<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('membership_plan_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('price', 12, 2);

            $table->timestamp('started_at');

            $table->timestamp('expired_at');

            $table->string('status')->default('active');

            $table->string('payment_reference')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('expired_at');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};