<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('contentions', function (Blueprint $table) {
            $table->uuid('contention_id')->primary();
            $table->uuid('concert_id');
            $table->string('seat_id');
            $table->integer('buyers_requested');
            $table->timestampTz('started_at');
        });

        Schema::create('contention_buyers', function (Blueprint $table) {
            $table->uuid('contention_id');
            $table->integer('buyer_number');
            $table->uuid('customer_id')->unique();
            $table->uuid('order_id')->unique();
            $table->string('hold_id');
            $table->primary(['contention_id', 'buyer_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contention_buyers');
        Schema::dropIfExists('contentions');
    }
};
