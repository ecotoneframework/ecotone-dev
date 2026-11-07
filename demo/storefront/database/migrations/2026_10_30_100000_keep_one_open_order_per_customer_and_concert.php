<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX orders_one_open_order_per_customer_and_concert ON orders (customer_id, concert_id) WHERE status IN ('started', 'seats_held')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX orders_one_open_order_per_customer_and_concert');
    }
};
