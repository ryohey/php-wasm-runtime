# php-wasm-runtime

A WebAssembly runtime implemented in pure PHP. No C extensions required — PHP decoding and executing Wasm binary (`.wasm`) format directly.

## Features

- **Pure PHP** — runs on PHP 8.1+ with no extensions
- **Binary format** — reads `.wasm` files directly via a custom binary decoder
- **WASI support** — `wasi_snapshot_preview1` (stdio, filesystem, clock, random, args, env)
- **Iterative interpreter** — label-stack based execution (no recursion per block level)
- **Spec test compatible** — runs official `.wast` test suite via WABT's `wast2json` and PHPUnit

## Supported

| Category | Details |
|---|---|
| Value types | `i32`, `i64`, `f32`, `f64` |
| Instructions | Arithmetic, comparison, bitwise, conversion, memory, control flow |
| Control flow | `block`, `loop`, `if/else`, `br`, `br_if`, `br_table`, `return`, `return_call` |
| Calls | Direct `call`, indirect `call_indirect`, tail calls `return_call`/`return_call_indirect` |
| Memory | Linear memory, `memory.grow/size/fill/copy/init`, all load/store widths |
| Tables | `funcref`/`externref` tables, `table.get/set/grow/size/fill/copy/init` |
| Globals | Mutable and immutable globals |
| Imports | Host functions, memory, tables, and globals |
| Exports | Functions, memory, tables, and globals |
| Bulk memory | `memory.fill`, `memory.copy`, `memory.init`, `data.drop` |
| Bulk table | `table.fill`, `table.copy`, `table.init`, `elem.drop` |
| WASI | `fd_read/write`, `path_open`, `args_get`, `environ_get`, `clock_time_get`, `random_get`, `proc_exit`, … |

## Architecture

```
packages/
├── runtime/src/           Core Wasm runtime
│   ├── ValType.php          Value type constants (I32, I64, F32, F64, FUNCREF, EXTERNREF)
│   ├── WasmValue.php        Runtime value (type + value pair)
│   ├── FuncType.php         Function signature (params[], results[])
│   ├── Trap.php             Runtime trap exception
│   ├── WasmError.php        Validation/parse error
│   ├── Module.php           Module definition (decoded from .wasm binary)
│   ├── Memory.php           Linear memory backed by a PHP string buffer
│   ├── Table.php            Function reference table
│   ├── Instance.php         Module instantiation and export dispatch
│   ├── Executor.php         Iterative Wasm interpreter
│   ├── Validator.php        Module validation
│   ├── Binary/
│   │   ├── Decoder.php      Wasm binary decoder (.wasm → Module)
│   │   └── BinaryReader.php Low-level binary format reader (LEB128, etc.)
│   └── Wast/
│       └── Runner.php       .wast spec test runner (uses WABT wast2json)
├── wasi/src/              WASI implementation
│   ├── Wasi.php             wasi_snapshot_preview1 syscalls
│   ├── Errno.php            POSIX error code constants
│   └── WasiExitException.php  proc_exit exception
└── cli/                   Command-line interface
    ├── bin/wasm             CLI entry point
    └── src/WasmRuntimeCLI.php  Standard & WASI execution modes
```

### Execution pipeline

```
.wasm binary
    │
    ▼  Binary\Decoder → Module (types, functions, memories, tables, globals, …)
    │   decodes sections, resolves indices, pre-computes branch target IPs
    │
    ▼  Instance::instantiate() → resolve imports, init memories/tables/globals
    │
    ▼  Executor::run() → label-stack iterative interpreter
```

### Pre-computed branch targets

The binary decoder pre-computes branch target IPs for `block`/`loop`/`if` during decoding, eliminating runtime nesting analysis and enabling a tight iterative dispatch loop.

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

**For running spec tests:** [WABT](https://github.com/WebAssembly/wabt) (provides `wat2wasm` and `wast2json`)

## Usage examples

### Basic module execution

```php
<?php
require 'vendor/autoload.php';

use WasmRuntime\Binary\Decoder;
use WasmRuntime\Instance;
use WasmRuntime\WasmValue;

// Decode a .wasm binary file
$module = Decoder::decodeFile('example.wasm');

$instance = Instance::instantiate($module);

$results = $instance->callExport('add', [
    WasmValue::i32(10),
    WasmValue::i32(32),
]);

echo $results[0]->value; // 42
```

### Importing host functions

```php
$module = Decoder::decodeFile('module_with_imports.wasm');

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

### WASI module execution

```php
use WasmRuntime\Binary\Decoder;
use WasmRuntime\Instance;
use WasmRuntime\Wasi\Wasi;
use WasmRuntime\Wasi\WasiExitException;

$module = Decoder::decodeFile('hello.wasm');

$wasi = new Wasi(
    args: ['hello.wasm', '--verbose'],
    env:  ['HOME' => '/home/user'],
    preopenDirs: ['/tmp'],
);

$instance = Instance::instantiate($module, $wasi->getImports());
$wasi->bindInstance($instance);

try {
    $instance->callExport('_start', []);
} catch (WasiExitException $e) {
    echo "Exit code: {$e->exitCode}\n";
}
```

### Running a .wast spec file

The WAST runner uses WABT's `wast2json` tool to convert `.wast` files into JSON + `.wasm` binaries, then executes the test commands.

```php
use WasmRuntime\Wast\Runner;

$runner = new Runner();
$result = $runner->runFile('tests/spec/i32.wast');

echo "passed: {$result['passed']}\n";
echo "failed: {$result['failed']}\n";
echo "total:  {$result['total']}\n";
```

## CLI

```bash
# Standard mode: run an exported function
php packages/cli/bin/wasm example.wasm add 10 32
# => 42

# WASI mode: run a WASI program
php packages/cli/bin/wasm --wasi hello.wasm

# WASI with pre-opened directory and arguments
php packages/cli/bin/wasm --wasi --dir=. app.wasm arg1 arg2

# Argument type prefixes (default is i32)
php packages/cli/bin/wasm example.wasm mul f64:3.14 f64:2.0
```

## Running tests

```bash
# Run all tests
./vendor/bin/phpunit

# Filter to a specific spec file
./vendor/bin/phpunit --filter "testOfficialSpec.*i32"

# Run local spec tests only
./vendor/bin/phpunit --filter testLocalSpec

# Run a specific inline test
./vendor/bin/phpunit --filter testI32BasicArithmetic
```

### Test architecture

Tests use WABT's `wast2json` to convert `.wast` (WebAssembly Script Test) files into:
- A JSON file describing test commands (module loads, assertions, traps, etc.)
- One or more `.wasm` binary files for each module defined in the `.wast`

The `Wast\Runner` then parses the JSON and executes each command against the PHP runtime.

### Spec test files

Local tests (hand-written, under `tests/spec/`):

| File | Coverage |
|---|---|
| `tests/spec/i32.wast` | Arithmetic, comparison, bitwise, sign extension |
| `tests/spec/i64.wast` | 64-bit integer operations |
| `tests/spec/f64.wast` | Floating-point arithmetic and math functions |
| `tests/spec/control.wast` | block/loop/if/br/br_if/br_table/select |
| `tests/spec/memory.wast` | Load/store, grow/size, out-of-bounds traps |
| `tests/spec/call.wast` | Recursion, mutual recursion |
| `tests/spec/globals.wast` | Mutable and immutable globals |

Official WebAssembly spec tests are also run from the `spec/test/core/` submodule (see `SPEC_COVERAGE.md` for details).

### WASI tests

The official [wasi-testsuite](https://github.com/WebAssembly/wasi-testsuite) is included as a submodule:

```bash
# Build test binaries (requires Rust with wasm32-wasip1 target)
rustup target add wasm32-wasip1
cd scripts/wasi-testsuite
cargo build --manifest-path=tests/rust/wasm32-wasip1/Cargo.toml --target=wasm32-wasip1

# Run WASI tests
php scripts/wasi-tests/run.php
```

See `WASI_COVERAGE.md` for detailed results (9/46 passing).

## WASI support

Implements `wasi_snapshot_preview1` with the following syscalls:

| Syscall | Status |
|---|---|
| `args_get` / `args_sizes_get` | ✅ |
| `environ_get` / `environ_sizes_get` | ✅ |
| `clock_time_get` | ✅ |
| `random_get` | ✅ |
| `proc_exit` | ✅ |
| `fd_read` / `fd_write` | ✅ |
| `fd_close` / `fd_seek` | ✅ |
| `fd_fdstat_get` | ✅ Basic |
| `fd_prestat_get` / `fd_prestat_dir_name` | ✅ |
| `path_open` | ✅ Basic |
| `fd_filestat_get` / `path_filestat_get` | ❌ Not yet |
| `fd_readdir` | ❌ Not yet |
| `path_create_directory` / `path_remove_directory` | ❌ Not yet |
| `path_symlink` / `path_readlink` / `path_link` | ❌ Not yet |
| `path_rename` / `path_unlink_file` | ❌ Not yet |
| `poll_oneoff` | ❌ Not yet |

See `WASI_COVERAGE.md` for full test results and implementation details.

## Limitations

- Covers the Wasm MVP + bulk memory/table operations
- Proposals (SIMD, threads, exceptions, GC, typed function references) are not supported
- NaN bit-pattern propagation is simplified (PHP float limitation)
- Multi-module linking has partial support (some imports.wast/linking.wast cases fail)
- WASI filesystem operations are partially implemented (basic read/write/open; no stat, readdir, symlinks)
