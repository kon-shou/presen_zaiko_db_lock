<?php

namespace App\Services;

use App\Models\Stock;
use RuntimeException;

// app/Services/BuggyStockService.php
//
// ❌ 違算する実装。
// 「在庫を読む → アプリ側で判定する → 書き戻す」を、ロックもトランザクションも無しでやっている。
// 読取と書込の“あいだ”に別トランザクションが割り込むと、片方の減算が上書きで消える（lost update）。
//
// 補足（スライド①の粒度）:
//   単一の `UPDATE stocks SET quantity = quantity - 1` だけなら InnoDB では原子的で違算しない。
//   違算するのは、まさにこの「読んで・アプリで判定して・書き戻す」パターン。
class BuggyStockService
{
    /**
     * @param  string|null    $connection  使用する接続名（デモで mysql / mysql2 を切替）
     * @param  callable|null   $between     読取と書込の“あいだ”に差し込むフック（本番では null）。
     *                                      テストではここで別接続の減算を割り込ませ、競合を再現する。
     */
    public function decrement(int $stockId, int $by, ?string $connection = null, ?callable $between = null): void
    {
        // 1) 読む（ロックなし = スナップショット読み。最新コミットを待たない）
        $stock = Stock::on($connection)->findOrFail($stockId);

        // 2) アプリ側で判定する
        if ($stock->quantity < $by) {
            throw new RuntimeException('在庫が足りません');
        }

        // ── ここが危険な窓。別トランザクションが同じ在庫を読んで書いてしまう ──
        if ($between !== null) {
            $between();
        }

        // 3) さっき読んだ値を基準に書き戻す（他者の更新を知らずに上書きする）
        $stock->quantity -= $by;
        $stock->save();
    }
}
