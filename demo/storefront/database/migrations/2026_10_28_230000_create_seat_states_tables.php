<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('seat_holds', function (Blueprint $table) {
            $table->string('concert_id');
            $table->string('seat_id');
            $table->string('hold_id');
            $table->string('order_id');
            $table->boolean('ended');
            $table->timestampTz('expires_at')->nullable();
            $table->primary(['concert_id', 'seat_id', 'hold_id']);
        });

        Schema::create('sold_seats', function (Blueprint $table) {
            $table->string('concert_id');
            $table->string('seat_id');
            $table->string('order_id');
            $table->primary(['concert_id', 'seat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sold_seats');
        Schema::dropIfExists('seat_holds');
    }
};
