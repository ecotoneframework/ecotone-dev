<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id')->index();
            $table->string('recipient');
            $table->string('subject');
            $table->text('body');
            $table->timestampTz('sent_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
