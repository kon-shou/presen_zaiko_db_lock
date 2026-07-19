<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// app/Services/SafeStockService.php
//
// ✅ 直した実装。
// `DB::transaction()` の中で `lockForUpdate()`（= SQL の FOR UPDATE）を取り、
// 「読む → 判定 → 書く」を1つの排他区間に閉じ込める。
// 先行トランザクションがコミットするまで、後続の同じ行の locking read は待たされ、
// 待った側は“最新のコミット済みの値”を読み直してから減算する。
//
// - lockForUpdate() → MySQL / PostgreSQL とも `FOR UPDATE`
// - DB::transaction($cb, attempts: N) の N は「デッドロック時に限り」リトライする総試行回数
class SafeStockService
{
    /**
     * @param  string|null   $connection
     * @param  callable|null  $between  読取（＝ロック取得後）と書込のあいだに差し込むフック（本番では null）。
     *                                   テストでは「別接続が今この行をロックできないこと」を確認するのに使う。
     */
    public function decrement(int $stockId, int $by, ?string $connection = null, ?callable $between = null): void
    {
        DB::connection($connection)->transaction(function () use ($stockId, $by, $connection, $between) {
            // 1) 行を FOR UPDATE でロックしつつ読む（他トランザクションはこの行の locking read で待たされる）
            $stock = Stock::on($connection)
                ->lockForUpdate()
                ->findOrFail($stockId);

            // 2) 判定
            if ($stock->quantity < $by) {
                throw new RuntimeException('在庫が足りません');
            }

            // テスト用フック：ロックを握っている“この瞬間”に、別接続が同じ行を取れないことを見せる
            if ($between !== null) {
                $between();
            }

            // 3) 書く。ロック区間の中なので、読んだ値は誰にも上書きされていない
            $stock->quantity -= $by;
            $stock->save();
        }, attempts: 3); // ← デッドロック（テレコ等）で落ちたら最大3回まで自動リトライ
    }
}
