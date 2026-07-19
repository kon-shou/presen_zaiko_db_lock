<?php

use App\Models\Stock;
use App\Services\BuggyStockService;
use App\Services\SafeStockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

// tests/Feature/StockLostUpdateTest.php  （Pest）
//
// 【重要】このテストは RefreshDatabase を使わない。
//   RefreshDatabase / DatabaseTransactions は各テストを1つのトランザクションで包んで
//   最後に rollback するため、別接続からは未コミット行が見えず、ロックの検証も壊れる。
//   → DatabaseMigrations（毎回マイグレートし直す）を使い、各アクターを本物のコミットで動かす。
//
// 【重要】SQLite では動かない。SQLite は行ロック非対応で FOR UPDATE が実質 no-op。
//   本物の MySQL 8.0.1+（NOWAIT のため） / PostgreSQL が必要。config/database.php に
//   同じDBを指す 'mysql2' 接続を用意しておくこと（README 参照）。
uses(DatabaseMigrations::class);

it('ロック無しだと減算が上書きで消える（在庫違算）', function () {
    $stock = Stock::create(['sku' => 'A-001', 'quantity' => 100]);
    $buggy = new BuggyStockService();

    // 接続A が「読む→(窓)→書く」の“窓”の中で、接続B が丸ごと1回減算を割り込ませる。
    //   A: 100を読む … B が割り込み(100を読んで90を書く) … A が 90 を書く（Bの結果を上書き）
    $buggy->decrement($stock->id, 10, 'mysql', function () use ($buggy, $stock) {
        $buggy->decrement($stock->id, 10, 'mysql2'); // 別接続＝別セッション
    });

    // 20個ぶん減らしたはずが、最終在庫は 90。1回ぶん(10)の減算が消えた＝違算。
    expect(Stock::find($stock->id)->quantity)->toBe(90); // ❌ 本来は 80
});

it('lockForUpdate なら別接続は締め出され、違算しない', function () {
    $stock = Stock::create(['sku' => 'B-001', 'quantity' => 100]);
    $safe = new SafeStockService();

    $otherWasBlocked = false;

    // 接続A がトランザクション内で行を FOR UPDATE 中に、
    // 接続B が同じ行を FOR UPDATE NOWAIT で取ろうとする → 即エラー＝ちゃんと締め出されている。
    $safe->decrement($stock->id, 10, 'mysql', function () use (&$otherWasBlocked, $stock) {
        try {
            DB::connection('mysql2')->table('stocks')
                ->where('id', $stock->id)
                ->lock('for update nowait') // MySQL 8.0.1+ / PostgreSQL：取れなければ待たず即エラー
                ->first();
        } catch (QueryException $e) {
            $otherWasBlocked = true; // ロックが効いている証拠
        }
    });

    // A のロック区間中、B は同じ行をロックできなかった
    expect($otherWasBlocked)->toBeTrue();

    // A コミット後（=90）に、B が正しく読み直して減算 → 80
    $safe->decrement($stock->id, 10, 'mysql2');
    expect(Stock::find($stock->id)->quantity)->toBe(80); // ✅ 正しい
});
