# php-wasm-runtime

Pure PHP による WebAssembly ランタイムの実装です。C モジュールや外部ライブラリを一切使用せず、PHP だけで WAT (WebAssembly Text Format) のパースから実行まで行います。

## 特徴

- **Pure PHP** — PHP 8.1+ のみで動作。拡張モジュール不要
- **WAT/WAST パーサー** — S 式形式のテキストフォーマットを直接パース
- **イテレーティブ実行** — ラベルスタック方式のインタープリター（再帰なし）
- **公式スペックテスト対応** — `.wast` ファイルを直接 PHPUnit で実行

## 対応機能

| カテゴリ | 対応内容 |
|---|---|
| 値型 | `i32`, `i64`, `f32`, `f64` |
| 命令 | 算術・比較・ビット演算・変換・メモリ・制御フロー |
| 制御フロー | `block`, `loop`, `if/else`, `br`, `br_if`, `br_table`, `return` |
| ラベル | `block $l`, `loop $l`, `br $label` など名前付きラベル |
| 関数 | 直接呼び出し `call`、間接呼び出し `call_indirect` |
| メモリ | 線形メモリ、`memory.grow/size`、各サイズのロード/ストア |
| テーブル | `funcref` テーブル、要素セグメント |
| グローバル | mutable/immutable グローバル変数 |
| インポート | ホスト関数・メモリ・テーブル・グローバルのインポート |
| エクスポート | 関数・メモリ・テーブル・グローバルのエクスポート |

## アーキテクチャ

```
src/WasmRuntime/
├── ValType.php       値型定数 (I32, I64, F32, F64, FUNCREF, EXTERNREF)
├── WasmValue.php     ランタイム値 (型 + 値のペア)
├── FuncType.php      関数シグネチャ (params[], results[])
├── Trap.php          ランタイムトラップ例外
├── WasmError.php     検証・パースエラー
├── Module.php        モジュール定義 (パース結果)
├── Memory.php        線形メモリ (PHP 文字列バッファ)
├── Table.php         関数参照テーブル
├── Instance.php      モジュールインスタンス化・エクスポート呼び出し
├── Executor.php      イテレーティブ Wasm インタープリター
├── Wat/
│   ├── Lexer.php     WAT トークナイザー
│   ├── Token.php     トークン型定義
│   └── Parser.php    WAT/WAST パーサー (2パスコンパイル)
└── Wast/
    └── Runner.php    .wast スペックテストランナー
```

### 実行フロー

```
WAT ソース
    │
    ▼ Wat\Lexer → トークン列
    │
    ▼ Wat\Parser (1パス目) → 命令ツリー (S 式)
    │
    ▼ Wat\Parser (2パス目) → フラットバイトコード
    │   block/loop/if の分岐先 IP を事前計算
    │
    ▼ Instance::instantiate() → インポート解決・データ/要素セグメント初期化
    │
    ▼ Executor::run() → ラベルスタック方式インタープリター
```

### 2パスコンパイル

WAT パーサーは `block`/`loop`/`if` の分岐先 IP を**事前計算**してフラットバイトコードに変換します。これにより実行時のネスト解析が不要になり、高速なイテレーティブ実行が可能です。

```
block $b (result i32)   →   IP 0: ['block', i32, endIp=3]
  i32.const 1               IP 1: ['i32.const', 1]
  br $b                     IP 2: ['br', 0]
end                         IP 3: ['end']
```

`loop` の `contIp` はループ先頭を、`block`/`if` の `contIp` は `end` の次を指します。`br N` はラベルスタックを `N` 段たどって対応するブロックの `contIp` へジャンプします。

## セットアップ

```bash
git clone <repo>
cd php-wasm-runtime
composer install
```

**必要環境:** PHP 8.1 以上、Composer

## テスト実行

```bash
# 全テスト実行
./vendor/bin/phpunit

# 特定のスペックファイルのみ
./vendor/bin/phpunit --filter "testWastFile.*i32"

# インラインテストのみ
./vendor/bin/phpunit --filter testI32BasicArithmetic
```

テストスイートには以下が含まれます:

| テスト | 内容 |
|---|---|
| `tests/spec/i32.wast` | i32 算術・比較・ビット演算・符号拡張 |
| `tests/spec/f64.wast` | f64 浮動小数点演算 |
| `tests/spec/control.wast` | block/loop/if/br/br_if/br_table/select |
| `tests/spec/memory.wast` | メモリロード/ストア・grow/size・範囲外アクセス |
| `tests/spec/call.wast` | 再帰・相互再帰関数呼び出し |
| `tests/spec/globals.wast` | mutable/immutable グローバル変数 |

## コードサンプル

### 基本的な WAT モジュールの実行

```php
<?php
require 'vendor/autoload.php';

use WasmRuntime\Wat\Parser;
use WasmRuntime\Instance;
use WasmRuntime\WasmValue;
use WasmRuntime\ValType;

// WAT ソースをパースしてインスタンス化
$module = (new Parser())->parseModule('
    (module
        (func (export "add") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            i32.add)
    )
');

$instance = Instance::instantiate($module);

// エクスポートされた関数を呼び出す
$args = [
    WasmValue::i32(10),
    WasmValue::i32(32),
];
$results = $instance->callExport('add', $args);

echo $results[0]->value; // 42
```

### 再帰関数 (フィボナッチ数列)

```php
$module = (new Parser())->parseModule('
    (module
        (func $fib (export "fib") (param i64) (result i64)
            local.get 0
            i64.const 2
            i64.lt_s
            if (result i64)
                local.get 0
            else
                local.get 0
                i64.const 1
                i64.sub
                call $fib
                local.get 0
                i64.const 2
                i64.sub
                call $fib
                i64.add
            end)
    )
');

$instance = Instance::instantiate($module);
$result = $instance->callExport('fib', [WasmValue::i64(10)]);
echo $result[0]->value; // 55
```

### 線形メモリの利用

```php
$module = (new Parser())->parseModule('
    (module
        (memory (export "mem") 1)
        (func (export "store") (param i32 i32)
            local.get 0
            local.get 1
            i32.store)
        (func (export "load") (param i32) (result i32)
            local.get 0
            i32.load)
    )
');

$instance = Instance::instantiate($module);

// アドレス 0 に値 12345 を書き込む
$instance->callExport('store', [WasmValue::i32(0), WasmValue::i32(12345)]);

// 読み戻す
$result = $instance->callExport('load', [WasmValue::i32(0)]);
echo $result[0]->value; // 12345
```

### ホスト関数のインポート

```php
$module = (new Parser())->parseModule('
    (module
        (import "env" "log" (func $log (param i32)))
        (func (export "run") (param i32)
            local.get 0
            call $log)
    )
');

$imports = [
    'env' => [
        'log' => function (array $args): void {
            echo 'Wasm says: ' . $args[0]->value . PHP_EOL;
        },
    ],
];

$instance = Instance::instantiate($module, $imports);
$instance->callExport('run', [WasmValue::i32(42)]);
// Wasm says: 42
```

### .wast スペックファイルの実行

```php
use WasmRuntime\Wast\Runner;

$runner = new Runner();
$result = $runner->run(file_get_contents('tests/spec/i32.wast'));

echo "passed: {$result['passed']}\n";
echo "failed: {$result['failed']}\n";
echo "total:  {$result['total']}\n";
```

## 制限事項

- Wasm MVP (バージョン 1.0) の主要命令セットをカバー
- SIMD・スレッド・例外処理などの拡張提案は未対応
- バイナリ形式 (`.wasm`) の直接読み込みは未対応 (テキスト形式 `.wat`/`.wast` のみ)
- 浮動小数点の NaN 伝播は簡易実装
