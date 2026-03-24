<?php

declare(strict_types=1);

namespace WasmRuntime;

use WasmRuntime\Wasi\Wasi;
use WasmRuntime\Wasi\WasiExitException;
use WasmRuntime\Wat\Parser;

/**
 * CLI wrapper for WasmRuntime.
 *
 * Usage (standard mode):
 *   php bin/wasm <file.wat> [function] [arg1 arg2 ...]
 *
 * Usage (WASI mode):
 *   php bin/wasm --wasi [--dir=<path>] <file.wat> [wasi-args...]
 *
 * Standard mode:
 *   - If no function is specified, tries '_start' or 'main'.
 *   - Arguments default to i32; prefix with 'i64:', 'f32:', or 'f64:' to override.
 *
 * WASI mode (--wasi):
 *   - Enables the wasi_snapshot_preview1 interface.
 *   - Always calls the '_start' export.
 *   - Remaining arguments after the file path become WASI argv.
 *   - --dir=<path>  Pre-open a directory for WASI path access (repeatable).
 */
final class WasmRuntimeCLI
{
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        if (count($args) === 0 || in_array($args[0], ['-h', '--help'], true)) {
            $this->printUsage();
            return 0;
        }

        // ---- Parse flags ----
        $wasiMode    = false;
        $preopenDirs = [];
        $rest        = [];

        foreach ($args as $arg) {
            if ($arg === '--wasi') {
                $wasiMode = true;
            } elseif (str_starts_with($arg, '--dir=')) {
                $preopenDirs[] = substr($arg, 6);
            } else {
                $rest[] = $arg;
            }
        }
        $args = $rest;

        if (count($args) === 0) {
            fwrite(STDERR, "Error: no input file specified.\n");
            return 1;
        }

        $file = array_shift($args);

        if (!file_exists($file)) {
            fwrite(STDERR, "Error: file not found: $file\n");
            return 1;
        }

        // ---- WASI mode ----
        if ($wasiMode) {
            return $this->runWasi($file, $args, $preopenDirs);
        }

        // ---- Standard mode ----
        return $this->runStandard($file, $args);
    }

    // ------------------------------------------------------------------ //
    //  Standard (non-WASI) execution                                      //
    // ------------------------------------------------------------------ //

    private function runStandard(string $file, array $args): int
    {
        try {
            $instance = $this->loadAndInstantiate($file);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error loading module: " . $e->getMessage() . "\n");
            return 1;
        }

        // Determine function to call
        $funcName = null;
        $funcArgs = [];

        if (count($args) > 0 && !is_numeric($args[0]) && !str_contains($args[0], ':')) {
            $funcName = array_shift($args);
            $funcArgs = $this->parseArgs($args);
        } else {
            $funcArgs = $this->parseArgs($args);
            foreach (['_start', 'main'] as $candidate) {
                if (isset($instance->module->exports[$candidate])) {
                    $funcName = $candidate;
                    break;
                }
            }
        }

        if ($funcName === null) {
            fwrite(STDERR, "Error: no function specified and no '_start' or 'main' export found.\n");
            $this->printExports($instance);
            return 1;
        }

        try {
            $results = $instance->callExport($funcName, $funcArgs);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error calling '$funcName': " . $e->getMessage() . "\n");
            return 1;
        }

        foreach ($results as $result) {
            echo $result->value . "\n";
        }

        return 0;
    }

    // ------------------------------------------------------------------ //
    //  WASI execution                                                     //
    // ------------------------------------------------------------------ //

    private function runWasi(string $file, array $wasiArgs, array $preopenDirs): int
    {
        // argv[0] is the program name (the WAT file path)
        $argv = array_merge([$file], $wasiArgs);

        // Build env from $_SERVER (filter to KEY=VALUE pairs)
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        $wasi = new Wasi(args: $argv, env: $env, preopenDirs: $preopenDirs);

        try {
            $module   = $this->parseModule($file);
            $instance = Instance::instantiate($module, $wasi->getImports());
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error loading module: " . $e->getMessage() . "\n");
            return 1;
        }

        $wasi->bindInstance($instance);

        if (!isset($instance->module->exports['_start'])) {
            fwrite(STDERR, "Error: WASI module must export '_start'.\n");
            return 1;
        }

        try {
            $instance->callExport('_start', []);
        } catch (WasiExitException $e) {
            return $e->exitCode;
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return 1;
        }

        return 0;
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function loadAndInstantiate(string $file): Instance
    {
        return Instance::instantiate($this->parseModule($file));
    }

    private function parseModule(string $file): Module
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'wat') {
            $source = file_get_contents($file);
            return (new Parser())->parseModule($source);
        }

        throw new WasmError("Unsupported file format: .$ext (only .wat is supported)");
    }

    /**
     * Parse CLI argument strings into WasmValue[].
     * Default type is i32. Prefix with "i64:", "f32:", or "f64:" to override.
     *
     * @param  string[] $args
     * @return WasmValue[]
     */
    private function parseArgs(array $args): array
    {
        $values = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, 'i64:')) {
                $values[] = WasmValue::i64((int)substr($arg, 4));
            } elseif (str_starts_with($arg, 'f32:')) {
                $values[] = WasmValue::f32((float)substr($arg, 4));
            } elseif (str_starts_with($arg, 'f64:')) {
                $values[] = WasmValue::f64((float)substr($arg, 4));
            } else {
                $values[] = WasmValue::i32((int)$arg);
            }
        }
        return $values;
    }

    private function printExports(Instance $instance): void
    {
        $exports = array_filter(
            $instance->module->exports,
            fn($e) => $e['kind'] === 'func'
        );
        if (empty($exports)) {
            fwrite(STDERR, "No exported functions found.\n");
            return;
        }
        fwrite(STDERR, "Available exports: " . implode(', ', array_keys($exports)) . "\n");
    }

    private function printUsage(): void
    {
        echo <<<'USAGE'
        Usage:
          wasm <file.wat> [function] [arg1 arg2 ...]
          wasm --wasi [--dir=<path>] <file.wat> [wasi-args...]

        Standard mode:
          file.wat     Path to a WebAssembly text format file
          function     Exported function to call (default: _start or main)
          arg1 ...     Arguments (default type i32; prefix i64:, f32:, f64: to override)

        WASI mode (--wasi):
          Enables wasi_snapshot_preview1 and calls the '_start' export.
          --dir=<path>  Pre-open a directory for WASI path access (repeatable)
          wasi-args     Arguments passed as WASI argv to the program

        Examples:
          php bin/wasm example.wat
          php bin/wasm example.wat add 10 32
          php bin/wasm example.wat mul f64:3.14 f64:2.0
          php bin/wasm --wasi hello.wat
          php bin/wasm --wasi --dir=. app.wat myarg1 myarg2

        USAGE;
    }
}
