<?php

use App\Models\Stock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

// tests/Feature/StockDeadlockTest.php  （Pest）
//
// テレコ（逆順ロック）でデッドロックが起きる仕組みと、その回避を示す。
//
// 【正直な注意】1プロセス（1スレッド）では“本物の検知されたデッドロック”は起こせない。
//   本物のデッドロック（MySQL: エラー 1213 / SQLSTATE 40001、PostgreSQL: 40P01）は、
//   2つのトランザクションが「互いの1本目のロックを同時に握ったまま2本目を待つ」状態で発生し、
//   InnoDB/Postgres が循環を検知して片方をロールバックする。
//   単一プロセスでは“同時に待つ”を作れないので、ここでは NOWAIT で
//   「循環待ちの関係」そのものを決定的に可視化する。真の検知デッドロックの再現は
//   2プロセス（例：`php artisan stock:decrement` を並列起動）や pcntl_fork が要る。
uses(DatabaseMigrations::class);

it('逆順ロックは循環待ち＝デッドロックの条件を作る', function () {
    $a = Stock::create(['sku' => 'DL-A', 'quantity' => 100]);
    $b = Stock::create(['sku' => 'DL-B', 'quantity' => 100]);

    DB::connection('mysql')->beginTransaction();
    DB::connection('mysql2')->beginTransaction();

    try {
        // T1 は A → B の順で欲しい。まず A を確保。
        DB::connection('mysql')->table('stocks')->where('id', $a->id)->lockForUpdate()->first();
        // T2 は B → A の順（テレコ！）。まず B を確保。
        DB::connection('mysql2')->table('stocks')->where('id', $b->id)->lockForUpdate()->first();

        // ここで T1 は B を、T2 は A を欲しがる → 互いに相手待ち＝循環。
        // NOWAIT で「今は取れない」を即座に確認する（本番の待機ロックならここで循環し、片方が
        // 1213/40001 でロールバックされる）。
        $t1WantsBFails = false;
        $t2WantsAFails = false;

        try {
            DB::connection('mysql')->table('stocks')->where('id', $b->id)->lock('for update nowait')->first();
        } catch (QueryException $e) {
            $t1WantsBFails = true;
        }
        try {
            DB::connection('mysql2')->table('stocks')->where('id', $a->id)->lock('for update nowait')->first();
        } catch (QueryException $e) {
            $t2WantsAFails = true;
        }

        expect($t1WantsBFails)->toBeTrue();
        expect($t2WantsAFails)->toBeTrue(); // ← この相互不可がまさにデッドロックの正体
    } finally {
        DB::connection('mysql')->rollBack();
        DB::connection('mysql2')->rollBack();
    }
});

it('ロック順をそろえれば循環は生まれない（回避策）', function () {
    $a = Stock::create(['sku' => 'OK-A', 'quantity' => 100]);
    $b = Stock::create(['sku' => 'OK-B', 'quantity' => 100]);

    DB::connection('mysql')->beginTransaction();
    DB::connection('mysql2')->beginTransaction();

    try {
        // 両者とも「id の小さい順」で取る、と決めておく（常に A → B）。
        DB::connection('mysql')->table('stocks')->where('id', $a->id)->lockForUpdate()->first();

        // T2 も同じ順で A を欲しがる。取れない＝ただ待つだけ（循環ではない）。
        // T1 がコミットすれば T2 は順番に進める。デッドロックにはならない。
        $t2MustWaitForA = false;
        try {
            DB::connection('mysql2')->table('stocks')->where('id', $a->id)->lock('for update nowait')->first();
        } catch (QueryException $e) {
            $t2MustWaitForA = true;
        }

        expect($t2MustWaitForA)->toBeTrue(); // 待つのは1方向だけ＝安全
    } finally {
        DB::connection('mysql')->rollBack();
        DB::connection('mysql2')->rollBack();
    }

    // なお本番では、万一のデッドロックに備えて減算処理を
    //   DB::transaction(fn () => ..., attempts: 3)
    // で包み、1213/40001 のときだけ自動リトライさせる（SafeStockService 参照）。
});
