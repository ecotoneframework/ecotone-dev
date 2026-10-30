<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('rushes', function (Blueprint $table) {
            $table->uuid('rush_id')->primary();
            $table->uuid('concert_id');
            $table->integer('buyers_requested');
            $table->integer('seats_per_buyer');
            $table->integer('declined_share');
            $table->integer('outage_share');
            $table->integer('bounce_share');
            $table->integer('buyer_deadline_seconds');
            $table->timestampTz('started_at');
            $table->timestampTz('deadline_at');
            $table->integer('last_tick')->default(0);
            $table->timestampTz('finished_at')->nullable();
        });

        Schema::create('rush_buyers', function (Blueprint $table) {
            $table->uuid('rush_id');
            $table->integer('buyer_number');
            $table->uuid('customer_id')->unique();
            $table->uuid('order_id');
            $table->uuid('concert_id')->index();
            $table->string('role', 10);
            $table->string('stage', 10);
            $table->string('pending_seat_id')->nullable();
            $table->json('refused_seat_ids');
            $table->timestampTz('joined_at');
            $table->timestampTz('asked_at')->nullable();
            $table->primary(['rush_id', 'buyer_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rush_buyers');
        Schema::dropIfExists('rushes');
    }
};
