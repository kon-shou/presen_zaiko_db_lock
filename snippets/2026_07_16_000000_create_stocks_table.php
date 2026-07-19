<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// database/migrations/2026_07_16_000000_create_stocks_table.php
//
// 在庫テーブル。sku にユニーク制約を付けておく（後半の「ロックをしない解決法」の題材）。
// quantity は符号なし整数。マイナス在庫（＝違算）をDB側でも弾けるようにしている。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();      // 一意制約：重複SKUの並行INSERTはDBが1件だけ通す
            $table->unsignedInteger('quantity');  // 在庫数。unsigned なので負数を書こうとすると例外
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
