# 在庫違算デモ — Laravel スニペット

スライド「在庫違算を防ごう！トランザクション＆ロック」に添付するコード片です。
**貼り付け前提の実物ファイル**であって、そのままでは動くフルプロジェクトではありません。
下記のとおり Laravel 12 プロジェクトへ配置し、**本物の MySQL** を用意すると実際に走ります。

## ファイル一覧

| ファイル | 置き場所 | 役割 |
|---|---|---|
| `2026_07_16_000000_create_stocks_table.php` | `database/migrations/` | `stocks` テーブル（`sku` ユニーク / `quantity`） |
| `Stock.php` | `app/Models/` | Eloquent モデル |
| `BuggyStockService.php` | `app/Services/` | ❌ ロック無し。読む→判定→書き戻すで**違算する** |
| `SafeStockService.php` | `app/Services/` | ✅ `DB::transaction` + `lockForUpdate()` |
| `StockLostUpdateTest.php` | `tests/Feature/` | 違算の再現と、ロックでの解消を証明 |
| `StockDeadlockTest.php` | `tests/Feature/` | テレコ（逆順ロック）＝デッドロックの条件と回避 |

テストは **Pest**（Laravel 12 既定）記法です。PHPUnit なら `class StockLostUpdateTest extends TestCase { use DatabaseMigrations; function test_...(){} }` に読み替えてください。

## ⚠️ 2つの落とし穴（このデモの肝）

### 1. SQLite では再現できない
SQLite は**行ロック非対応**（ファイル/DB単位ロック）で、`FOR UPDATE` は実質 no-op。
`:memory:` は2接続で共有もできません。**本物の MySQL 8.0.1+ か PostgreSQL** を使ってください。
（`FOR UPDATE NOWAIT` を使うため MySQL は 8.0.1 以上。PostgreSQL も NOWAIT 対応）

### 2. `RefreshDatabase` は並行ロックの検証を壊す
`RefreshDatabase` / `DatabaseTransactions` は各テストを1つのトランザクションで包み、
最後に rollback します。すると別接続から未コミット行が見えず、ロックも検証できません。
→ 本デモは **`DatabaseMigrations`**（毎回マイグレートし直す）を使っています。

## セットアップ

### 1) 本物の MySQL を用意
Laravel Sail なら `docker compose up -d`。手元の MySQL でも可。テスト用DBを1つ作成します。

### 2) 2つ目の接続 `mysql2` を追加（同じDBを指す別セッション）
`config/database.php` の `connections` に、既存 `mysql` と同じ設定で名前だけ変えた
`mysql2` を足します。

```php
// config/database.php  → 'connections' => [ ... ]
'mysql'  => [ /* Laravel 既定のまま */ ],

'mysql2' => [
    'driver'   => 'mysql',
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),   // mysql と同じDBを指す
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
    'prefix'   => '',
    'strict'   => true,
],
```

> `mysql2` は「同じデータベースへの独立したもう1本のセッション」です。
> これで1プロセスの中でも“別トランザクション同士”の競合を再現できます。

### 3) テスト用 `.env`（`.env.testing`）で本物のDBを指す
```dotenv
DB_CONNECTION=mysql
DB_DATABASE=laravel_test
# 重要：SQLite :memory: にしないこと
```

### 4) 実行
```bash
php artisan test --filter=StockLostUpdateTest
php artisan test --filter=StockDeadlockTest
```

## 期待結果

- `StockLostUpdateTest`
  - 「ロック無し」→ 最終在庫 **90**（20売れたのに10しか引かれない＝違算）
  - 「lockForUpdate」→ 別接続は締め出され、最終在庫 **80**（正しい）
- `StockDeadlockTest`
  - 逆順ロックは相互に取れない＝循環待ち（デッドロックの正体）を可視化
  - ロック順をそろえれば待ちは1方向だけ＝安全

## 補足：本物の“検知されたデッドロック”を撮りたい場合

1プロセスでは**同時待ち**を作れないため、`StockDeadlockTest` は NOWAIT で
「循環関係」を決定的に見せています。実際に InnoDB が
`SQLSTATE 40001 / errno 1213`（PostgreSQL は `40P01`）でロールバックする様子を撮るには、
`php artisan stock:decrement {id}` のようなコマンドを作り、**2プロセス以上を並列起動**
（`&` / `xargs -P` / `Process::pool`）して同じ2行を逆順にロックさせてください。
そのとき `SafeStockService` の `DB::transaction(..., attempts: 3)` が
デッドロック時だけ自動リトライして最終的に成功する、という締めが撮れます。
