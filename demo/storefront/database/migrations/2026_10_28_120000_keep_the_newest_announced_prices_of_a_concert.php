<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('concerts', function (Blueprint $table) {
            $table->integer('prices_version')->default(0);
        });
        DB::table('concerts')->whereNotNull('changed_standard_price_cents')->update([
            'standard_price_cents' => DB::raw('changed_standard_price_cents'),
            'vip_price_cents' => DB::raw('changed_vip_price_cents'),
        ]);
        Schema::table('concerts', function (Blueprint $table) {
            $table->dropColumn(['changed_standard_price_cents', 'changed_vip_price_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('concerts', function (Blueprint $table) {
            $table->integer('changed_standard_price_cents')->nullable();
            $table->integer('changed_vip_price_cents')->nullable();
            $table->dropColumn('prices_version');
        });
    }
};
