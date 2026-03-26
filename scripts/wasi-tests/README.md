# WASI Test Suite Runner

Runs the official [wasi-testsuite](https://github.com/WebAssembly/wasi-testsuite) against this PHP Wasm runtime.

## Prerequisites

- Rust toolchain with `wasm32-wasip1` target:
  ```bash
  rustup target add wasm32-wasip1
  ```

- WASI test suite submodule:
  ```bash
  git submodule update --init scripts/wasi-testsuite
  ```

## Build test binaries

```bash
cd scripts/wasi-testsuite
cargo build --manifest-path=tests/rust/wasm32-wasip1/Cargo.toml --target=wasm32-wasip1
```

## Run tests

```bash
# Run all tests
php scripts/wasi-tests/run.php

# Filter tests
php scripts/wasi-tests/run.php --filter=clock

# Verbose output
php scripts/wasi-tests/run.php --verbose

# JSON output
php scripts/wasi-tests/run.php --json
```

## Test results

See [WASI_COVERAGE.md](../../WASI_COVERAGE.md) for detailed coverage information.
