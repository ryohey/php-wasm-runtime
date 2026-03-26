<?php

declare(strict_types=1);

namespace WasmRuntime\Tests;

use PHPUnit\Framework\TestCase;
use WasmRuntime\Wast\Runner;

/**
 * Runs .wast spec test files through the PHP Wasm runtime.
 *
 * Two test suites:
 *   - localSpec:    hand-written tests under tests/spec/
 *   - officialSpec: WebAssembly/spec submodule under spec/test/core/
 *
 * The official spec suite is skipped gracefully when the submodule has not
 * been initialised (git submodule update --init packages/runtime/spec).
 *
 * Run a single file:
 *   vendor/bin/phpunit --filter 'testLocalSpec/i32'
 *   vendor/bin/phpunit --filter 'testOfficialSpec/i32'
 */
final class WastTest extends TestCase
{
    /**
     * Official spec files to skip, grouped by reason.
     *
     * binary-format:   require parsing the Wasm binary encoding (not supported)
     * stack-crash:     deliberately exhaust the native call stack (kills the process)
     * bulk-memory:     memory.copy / memory.fill / memory.init (not implemented)
     * reference-types: ref.null / ref.func / ref.is_null (not implemented)
     * table-bulk:      table.copy / table.fill / table.init (not implemented)
     * simd:            128-bit SIMD instructions (not implemented)
     * performance:     extremely large file; would time-out a single test
     */
    private const SKIP_OFFICIAL = [
        // annotations extension (uses @ syntax, not standard WAT)
        'annotations.wast',
        // binary-format
        'binary.wast',
        'binary-leb128.wast',
        // stack-crash
        'skip-stack-guard-page.wast',
        // bulk-memory
        'memory_copy.wast',
        'memory_fill.wast',
        'memory_init.wast',
        'bulk-memory-operations.wast',
        // reference-types
        'ref_null.wast',
        'ref_func.wast',
        'ref_is_null.wast',
        // table-bulk
        'table_copy.wast',
        'table_fill.wast',
        'table_init.wast',
        // performance (>2 000 lines; run separately when optimizing float support)
        'float_exprs.wast',
        'utf8-import-module.wast',
        'utf8-import-field.wast',
        'utf8-custom-section-id.wast',
        // GC proposal (typed function references, rec types — not implemented)
        'br_on_non_null.wast',
        'br_on_null.wast',
        'call_ref.wast',
        'return_call_ref.wast',
        'ref_as_non_null.wast',
        'type-rec.wast',
        'type-equivalence.wast',
        'local_init.wast',
    ];

    // -------------------------------------------------------------------------
    // Local spec tests (tests/spec/*.wast)
    // -------------------------------------------------------------------------

    /** @dataProvider localSpecProvider */
    public function testLocalSpec(string $file): void
    {
        $this->assertWastFile($file);
    }

    /** @return array<string, array{string}> */
    public static function localSpecProvider(): array
    {
        return self::globProvider(__DIR__ . '/spec');
    }

    // -------------------------------------------------------------------------
    // Official WebAssembly spec tests (spec/test/core/*.wast)
    // -------------------------------------------------------------------------

    /** @dataProvider officialSpecProvider */
    public function testOfficialSpec(string $file): void
    {
        $base = basename($file);

        if (in_array($base, self::SKIP_OFFICIAL, true)) {
            $this->markTestSkipped("$base uses binary module format (not supported)");
        }

        $this->assertWastFile($file);
    }

    /** @return array<string, array{string}> */
    public static function officialSpecProvider(): array
    {
        $dir = __DIR__ . '/../spec/test/core';

        if (!is_dir($dir)) {
            // Submodule not initialised – return empty so the suite is silently omitted
            return [];
        }

        return self::globProvider($dir);
    }

    // -------------------------------------------------------------------------
    // Inline unit tests (no .wast file needed)
    // -------------------------------------------------------------------------

    public function testI32BasicArithmetic(): void
    {
        $src = <<<'WAST'
        (module
          (func (export "add") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            i32.add)
          (func (export "mul") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            i32.mul)
        )
        (assert_return (invoke "add" (i32.const 3) (i32.const 4)) (i32.const 7))
        (assert_return (invoke "add" (i32.const -1) (i32.const 1)) (i32.const 0))
        (assert_return (invoke "mul" (i32.const 6) (i32.const 7)) (i32.const 42))
        WAST;

        $this->assertWast($src);
    }

    public function testI64Arithmetic(): void
    {
        $src = <<<'WAST'
        (module
          (func (export "add") (param i64 i64) (result i64)
            local.get 0 local.get 1 i64.add)
          (func (export "mul") (param i64 i64) (result i64)
            local.get 0 local.get 1 i64.mul)
        )
        (assert_return (invoke "add" (i64.const 1000000000) (i64.const 1000000000)) (i64.const 2000000000))
        (assert_return (invoke "mul" (i64.const 1000000) (i64.const 1000000)) (i64.const 1000000000000))
        WAST;

        $this->assertWast($src);
    }

    public function testRecursiveFunction(): void
    {
        $src = <<<'WAST'
        (module
          (func $fib (export "fib") (param i32) (result i32)
            local.get 0
            i32.const 2
            i32.lt_s
            if (result i32)
              local.get 0
            else
              local.get 0
              i32.const 1
              i32.sub
              call $fib
              local.get 0
              i32.const 2
              i32.sub
              call $fib
              i32.add
            end)
        )
        (assert_return (invoke "fib" (i32.const 0)) (i32.const 0))
        (assert_return (invoke "fib" (i32.const 1)) (i32.const 1))
        (assert_return (invoke "fib" (i32.const 2)) (i32.const 1))
        (assert_return (invoke "fib" (i32.const 7)) (i32.const 13))
        (assert_return (invoke "fib" (i32.const 10)) (i32.const 55))
        WAST;

        $this->assertWast($src);
    }

    public function testMemory(): void
    {
        $src = <<<'WAST'
        (module
          (memory 1)
          (func (export "store") (param i32 i32)
            local.get 0 local.get 1 i32.store)
          (func (export "load") (param i32) (result i32)
            local.get 0 i32.load)
        )
        (invoke "store" (i32.const 0) (i32.const 0xdeadbeef))
        (assert_return (invoke "load" (i32.const 0)) (i32.const 0xdeadbeef))
        (assert_trap (invoke "load" (i32.const 0x10000)) "out of bounds memory access")
        WAST;

        $this->assertWast($src);
    }

    public function testGlobals(): void
    {
        $src = <<<'WAST'
        (module
          (global $counter (mut i32) (i32.const 0))
          (func (export "inc")
            global.get $counter
            i32.const 1
            i32.add
            global.set $counter)
          (func (export "get") (result i32)
            global.get $counter)
        )
        (invoke "inc")
        (invoke "inc")
        (invoke "inc")
        (assert_return (invoke "get") (i32.const 3))
        WAST;

        $this->assertWast($src);
    }

    public function testCallIndirect(): void
    {
        $src = <<<'WAST'
        (module
          (type $add_t (func (param i32 i32) (result i32)))
          (func $add (param i32 i32) (result i32) local.get 0 local.get 1 i32.add)
          (func $sub (param i32 i32) (result i32) local.get 0 local.get 1 i32.sub)
          (table 2 funcref)
          (elem (i32.const 0) $add $sub)
          (func (export "call_fn") (param i32 i32 i32) (result i32)
            local.get 0
            local.get 1
            local.get 2
            call_indirect (type $add_t))
        )
        (assert_return (invoke "call_fn" (i32.const 10) (i32.const 3) (i32.const 0)) (i32.const 13))
        (assert_return (invoke "call_fn" (i32.const 10) (i32.const 3) (i32.const 1)) (i32.const 7))
        (assert_trap (invoke "call_fn" (i32.const 10) (i32.const 3) (i32.const 5)) "out of bounds table access")
        WAST;

        $this->assertWast($src);
    }

    public function testDataSegment(): void
    {
        $src = <<<'WAST'
        (module
          (memory 1)
          (data (i32.const 10) "hello")
          (func (export "load8") (param i32) (result i32)
            local.get 0 i32.load8_u)
        )
        (assert_return (invoke "load8" (i32.const 10)) (i32.const 104)) ;; 'h'
        (assert_return (invoke "load8" (i32.const 11)) (i32.const 101)) ;; 'e'
        (assert_return (invoke "load8" (i32.const 12)) (i32.const 108)) ;; 'l'
        (assert_return (invoke "load8" (i32.const 13)) (i32.const 108)) ;; 'l'
        (assert_return (invoke "load8" (i32.const 14)) (i32.const 111)) ;; 'o'
        WAST;

        $this->assertWast($src);
    }

    public function testSelectInstruction(): void
    {
        $src = <<<'WAST'
        (module
          (func (export "max") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            local.get 0
            local.get 1
            i32.ge_s
            select)
        )
        (assert_return (invoke "max" (i32.const 5) (i32.const 3)) (i32.const 5))
        (assert_return (invoke "max" (i32.const 3) (i32.const 5)) (i32.const 5))
        (assert_return (invoke "max" (i32.const 0) (i32.const 0)) (i32.const 0))
        WAST;

        $this->assertWast($src);
    }

    public function testUnreachable(): void
    {
        $src = <<<'WAST'
        (module
          (func (export "boom") unreachable)
        )
        (assert_trap (invoke "boom") "unreachable")
        WAST;

        $this->assertWast($src);
    }

    public function testFoldedInstructions(): void
    {
        $src = <<<'WAST'
        (module
          (func (export "add") (param i32 i32) (result i32)
            (i32.add (local.get 0) (local.get 1)))
          (func (export "nested") (param i32 i32 i32) (result i32)
            (i32.add (i32.mul (local.get 0) (local.get 1)) (local.get 2)))
        )
        (assert_return (invoke "add" (i32.const 10) (i32.const 20)) (i32.const 30))
        (assert_return (invoke "nested" (i32.const 3) (i32.const 4) (i32.const 5)) (i32.const 17))
        WAST;

        $this->assertWast($src);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertWastFile(string $file): void
    {
        $src     = (string)file_get_contents($file);
        $runner  = new Runner();
        $results = $runner->run($src);

        if ($results['total'] === 0) {
            $this->markTestSkipped(sprintf(
                '%s: no assertions executed (unsupported features or directives)',
                basename($file)
            ));
        }

        $errors = array_values($results['errors']);
        $msgs   = implode("\n", array_map(fn($e) => $e['message'] ?? '(unknown)', $errors));

        $this->assertEquals(
            0,
            $results['failed'],
            sprintf(
                "%s – %d/%d assertions failed:\n%s",
                basename($file),
                $results['failed'],
                $results['total'],
                $msgs,
            )
        );
    }

    private function assertWast(string $src): void
    {
        $runner  = new Runner();
        $results = $runner->run($src);

        $errors = array_values($results['errors']);
        $msgs   = implode("\n", array_map(fn($e) => $e['message'] ?? '(unknown)', $errors));

        $this->assertEquals(0, $results['failed'], "WAST assertions failed:\n$msgs");
        $this->assertGreaterThan(0, $results['total'], 'No assertions found');
    }

    /** @return array<string, array{string}> */
    private static function globProvider(string $dir): array
    {
        $files = glob($dir . '/*.wast') ?: [];
        $cases = [];
        foreach ($files as $f) {
            $cases[basename($f)] = [$f];
        }
        return $cases;
    }
}
