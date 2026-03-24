# php-wasm-runtime

A WebAssembly runtime implemented in pure PHP. No C extensions or external libraries required — just PHP parsing and executing WAT (WebAssembly Text Format) from scratch.

## Features

- **Pure PHP** — runs on PHP 8.1+ with no extensions
- **WAT/WAST parser** — full S-expression text format support
- **Iterative interpreter** — label-stack based execution (no recursion per block level)
- **Spec test compatible** — runs `.wast` files directly via PHPUnit

## Supported

| Category | Details |
|---|---|
| Value types | `i32`, `i64`, `f32`, `f64` |
| Instructions | Arithmetic, comparison, bitwise, conversion, memory, control flow |
| Control flow | `block`, `loop`, `if/else`, `br`, `br_if`, `br_table`, `return` |
| Labels | Named labels: `block $l`, `loop $l`, `br $label` |
| Calls | Direct `call`, indirect `call_indirect` |
| Memory | Linear memory, `memory.grow/size`, all load/store widths |
| Tables | `funcref` tables, element segments |
| Globals | Mutable and immutable globals |
| Imports | Host functions, memory, tables, and globals |
| Exports | Functions, memory, tables, and globals |

## Architecture

```
src/WasmRuntime/
├── ValType.php          Value type constants (I32, I64, F32, F64, FUNCREF, EXTERNREF)
├── WasmValue.php        Runtime value (type + value pair)
├── FuncType.php         Function signature (params[], results[])
├── Trap.php             Runtime trap exception
├── WasmError.php        Validation/parse error
├── Module.php           Module definition (parse output)
├── Memory.php           Linear memory backed by a PHP string buffer
├── Table.php            Function reference table
├── Instance.php         Module instantiation and export dispatch
├── Executor.php         Iterative Wasm interpreter
├── WasmRuntimeCLI.php   CLI wrapper (bin/wasm entry point)
├── Wat/
│   ├── Lexer.php        WAT tokenizer
│   ├── Token.php        Token type definitions
│   └── Parser.php       WAT/WAST parser (two-pass compilation)
└── Wast/
    └── Runner.php       .wast spec test runner
```

### Execution pipeline

```
WAT source
    │
    ▼  Wat\Lexer → token stream
    │
    ▼  Wat\Parser  pass 1 → instruction tree (S-expressions)
    │
    ▼  Wat\Parser  pass 2 → flat bytecode
    │   pre-computes branch target IPs for block/loop/if
    │
    ▼  Instance::instantiate() → resolve imports, run data/element segments
    │
    ▼  Executor::run() → label-stack iterative interpreter
```

### Two-pass compilation

The WAT parser pre-computes branch target IPs for `block`/`loop`/`if` during compilation, eliminating runtime nesting analysis and enabling a tight iterative dispatch loop.

```
block $b (result i32)   →   IP 0: ['block', i32, endIp=3]
  i32.const 1               IP 1: ['i32.const', 1]
  br $b                     IP 2: ['br', 0]
end                         IP 3: ['end']
```

For `loop`, `contIp` points to the loop body start (re-entry on `br 0`). For `block`/`if`, `contIp` points past the `end`. `br N` walks N levels up the label stack and jumps to the target's `contIp`.

## Setup

```bash
git clone <repo>
cd php-wasm-runtime
composer install
```

**Requirements:** PHP 8.1+, Composer

## CLI

Run a WAT file directly from the command line:

```bash
php bin/wasm <file.wat> [function] [arg1 arg2 ...]
```

```bash
# Call a specific exported function with arguments
php bin/wasm example.wat add 10 32
# => 42

# Run _start or main automatically (if exported)
php bin/wasm example.wat

# Specify argument types with a prefix (default: i32)
php bin/wasm example.wat mul f64:3.14 f64:2.0

# Show help
php bin/wasm --help
```

Supported argument type prefixes: `i64:`, `f32:`, `f64:` (default is `i32`).

## Running tests

```bash
# Run all tests
./vendor/bin/phpunit

# Filter to a specific spec file
./vendor/bin/phpunit --filter "testWastFile.*i32"

# Run a specific inline test
./vendor/bin/phpunit --filter testI32BasicArithmetic
```

Spec test files:

| File | Coverage |
|---|---|
| `tests/spec/i32.wast` | Arithmetic, comparison, bitwise, sign extension |
| `tests/spec/f64.wast` | Floating-point arithmetic and math functions |
| `tests/spec/control.wast` | block/loop/if/br/br_if/br_table/select |
| `tests/spec/memory.wast` | Load/store, grow/size, out-of-bounds traps |
| `tests/spec/call.wast` | Recursion, mutual recursion |
| `tests/spec/globals.wast` | Mutable and immutable globals |

## Usage examples

### Basic module execution

```php
<?php
require 'vendor/autoload.php';

use WasmRuntime\Wat\Parser;
use WasmRuntime\Instance;
use WasmRuntime\WasmValue;

$module = (new Parser())->parseModule('
    (module
        (func (export "add") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            i32.add)
    )
');

$instance = Instance::instantiate($module);

$results = $instance->callExport('add', [
    WasmValue::i32(10),
    WasmValue::i32(32),
]);

echo $results[0]->value; // 42
```

### Recursive function (Fibonacci)

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

### Linear memory

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

$instance->callExport('store', [WasmValue::i32(0), WasmValue::i32(12345)]);
$result = $instance->callExport('load', [WasmValue::i32(0)]);
echo $result[0]->value; // 12345
```

### Importing host functions

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

### Running a .wast spec file

```php
use WasmRuntime\Wast\Runner;

$runner = new Runner();
$result = $runner->run(file_get_contents('tests/spec/i32.wast'));

echo "passed: {$result['passed']}\n";
echo "failed: {$result['failed']}\n";
echo "total:  {$result['total']}\n";
```

## Limitations

- Covers the Wasm MVP (version 1.0) core instruction set
- Proposals (SIMD, threads, exceptions, GC) are not supported
- Binary format (`.wasm`) is not supported — text format (`.wat`/`.wast`) only
- NaN bit-pattern propagation is simplified
