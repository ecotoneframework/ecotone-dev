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
            $table->string('artist')->nullable()->change();
            $table->string('venue_name')->nullable()->change();
            $table->timestampTz('starts_at')->nullable()->change();
            $table->string('currency', 3)->nullable()->change();
            $table->integer('standard_price_cents')->nullable()->change();
            $table->integer('vip_price_cents')->nullable()->change();
            $table->json('seats')->nullable()->change();
            $table->integer('changed_standard_price_cents')->nullable();
            $table->integer('changed_vip_price_cents')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('concerts')->whereNull('seats')->delete();
        DB::table('concerts')->whereNotNull('changed_standard_price_cents')->update([
            'standard_price_cents' => DB::raw('changed_standard_price_cents'),
            'vip_price_cents' => DB::raw('changed_vip_price_cents'),
        ]);
        Schema::table('concerts', function (Blueprint $table) {
            $table->dropColumn(['changed_standard_price_cents', 'changed_vip_price_cents']);
            $table->string('artist')->nullable(false)->change();
            $table->string('venue_name')->nullable(false)->change();
            $table->timestampTz('starts_at')->nullable(false)->change();
            $table->string('currency', 3)->nullable(false)->change();
            $table->integer('standard_price_cents')->nullable(false)->change();
            $table->integer('vip_price_cents')->nullable(false)->change();
            $table->json('seats')->nullable(false)->change();
        });
    }
};
