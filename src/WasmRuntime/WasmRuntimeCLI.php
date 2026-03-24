<?php

declare(strict_types=1);

namespace WasmRuntime;

use WasmRuntime\Wat\Parser;

/**
 * CLI wrapper for WasmRuntime.
 *
 * Usage:
 *   php bin/wasm <file.wat|file.wasm> [function] [arg1 arg2 ...]
 *
 * If no function is specified, tries to call "_start" or "main".
 * Arguments are parsed as i32 by default; prefix with "f32:" or "f64:" for floats,
 * or "i64:" for 64-bit integers.
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

        $file = array_shift($args);

        if (!file_exists($file)) {
            fwrite(STDERR, "Error: file not found: $file\n");
            return 1;
        }

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
            // Auto-detect entry point
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

    private function loadAndInstantiate(string $file): Instance
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'wat') {
            $source = file_get_contents($file);
            $module = (new Parser())->parseModule($source);
        } else {
            throw new WasmError("Unsupported file format: .$ext (only .wat is supported)");
        }

        return Instance::instantiate($module);
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
        Usage: wasm <file.wat> [function] [arg1 arg2 ...]

        Arguments:
          file.wat     Path to a WebAssembly text format file
          function     Exported function to call (default: _start or main)
          arg1 ...     Arguments passed to the function (default type: i32)
                       Prefix with i64:, f32:, or f64: to specify type

        Examples:
          php bin/wasm example.wat
          php bin/wasm example.wat add 10 32
          php bin/wasm example.wat mul f64:3.14 f64:2.0

        USAGE;
    }
}
