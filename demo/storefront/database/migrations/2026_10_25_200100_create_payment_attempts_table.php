<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->string('payment_reference')->primary();
            $table->string('card_ending', 4);
            $table->integer('attempts');
            $table->string('status', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
