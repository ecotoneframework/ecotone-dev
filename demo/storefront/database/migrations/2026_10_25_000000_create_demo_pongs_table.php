<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('demo_pongs', function (Blueprint $table) {
            $table->string('ping_id', 36)->index();
            $table->text('reply');
            $table->timestampTz('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_pongs');
    }
};
