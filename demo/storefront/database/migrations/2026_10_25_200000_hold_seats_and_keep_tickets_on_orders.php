<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('seat_ids');
            $table->json('held_seats')->default('[]');
            $table->json('last_refusal')->nullable();
            $table->json('tickets')->default('[]');
            $table->index(['customer_id', 'concert_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'concert_id', 'status']);
            $table->dropColumn(['held_seats', 'last_refusal', 'tickets']);
            $table->json('seat_ids')->default('[]');
        });
    }
};
