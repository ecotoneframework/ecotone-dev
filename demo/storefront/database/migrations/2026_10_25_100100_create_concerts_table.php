<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('concerts', function (Blueprint $table) {
            $table->uuid('concert_id')->primary();
            $table->string('artist');
            $table->string('venue_name');
            $table->timestampTz('starts_at');
            $table->string('currency', 3);
            $table->integer('standard_price_cents');
            $table->integer('vip_price_cents');
            $table->string('status', 20);
            $table->json('seats');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concerts');
    }
};
