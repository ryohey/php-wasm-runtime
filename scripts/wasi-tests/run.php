#!/usr/bin/env php
<?php
/**
 * WASI Test Suite Runner
 *
 * Runs the official wasi-testsuite (https://github.com/WebAssembly/wasi-testsuite)
 * against this PHP Wasm runtime's --wasi mode.
 *
 * Usage:
 *   php scripts/wasi-tests/run.php [--filter=<pattern>] [--verbose] [--json]
 *
 * Prerequisites:
 *   - Build the Rust test binaries first:
 *     cd scripts/wasi-testsuite && cargo build --manifest-path=tests/rust/wasm32-wasip1/Cargo.toml --target=wasm32-wasip1
 */

declare(strict_types=1);

// ---- Configuration ----

$projectRoot  = dirname(__DIR__, 2);
$suiteRoot    = $projectRoot . '/scripts/wasi-testsuite';
$wasmDir      = $suiteRoot . '/tests/rust/wasm32-wasip1/target/wasm32-wasip1/debug';
$srcDir       = $suiteRoot . '/tests/rust/wasm32-wasip1/src/bin';
$fixtureDir   = $srcDir . '/fs-tests.dir';
$cliPath      = $projectRoot . '/packages/cli/bin/wasm';

// Find PHP binary
$php = PHP_BINARY;
foreach (['/opt/homebrew/Cellar/php/8.5.4/bin/php', '/opt/homebrew/bin/php', '/usr/local/bin/php'] as $p) {
    if (file_exists($p)) { $php = $p; break; }
}

// ---- Parse CLI args ----

$filter  = null;
$verbose = false;
$jsonOut = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } elseif ($arg === '--json') {
        $jsonOut = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo <<<HELP
        WASI Test Suite Runner

        Usage: php scripts/wasi-tests/run.php [options]

        Options:
          --filter=<pattern>  Only run tests matching pattern (substring match)
          --verbose, -v       Show stdout/stderr for each test
          --json              Output results as JSON
          --help, -h          Show this help

        HELP;
        exit(0);
    }
}

// ---- Discover test cases ----

if (!is_dir($wasmDir)) {
    fwrite(STDERR, "Error: Wasm binaries not found at $wasmDir\n");
    fwrite(STDERR, "Build them first:\n");
    fwrite(STDERR, "  cd scripts/wasi-testsuite && cargo build --manifest-path=tests/rust/wasm32-wasip1/Cargo.toml --target=wasm32-wasip1\n");
    exit(1);
}

$wasmFiles = glob($wasmDir . '/*.wasm') ?: [];
// Filter out non-test artifacts (e.g. deps, build scripts)
$wasmFiles = array_filter($wasmFiles, function ($f) {
    $name = basename($f, '.wasm');
    // Skip files that look like build artifacts (contain hyphens typically from deps)
    return !str_contains($name, '-');
});
sort($wasmFiles);

if ($filter !== null) {
    $wasmFiles = array_filter($wasmFiles, fn($f) => str_contains(basename($f, '.wasm'), $filter));
    $wasmFiles = array_values($wasmFiles);
}

if (empty($wasmFiles)) {
    fwrite(STDERR, "No test cases found" . ($filter ? " matching '$filter'" : "") . "\n");
    exit(1);
}

// ---- Run tests ----

$results = [];
$passed  = 0;
$failed  = 0;
$errors  = 0;
$total   = count($wasmFiles);

if (!$jsonOut) {
    echo "Running $total WASI tests...\n\n";
}

foreach ($wasmFiles as $wasmFile) {
    $testName = basename($wasmFile, '.wasm');
    $jsonFile = $srcDir . '/' . $testName . '.json';

    // Parse test specification
    $spec = [
        'args'      => [],
        'dirs'      => [],
        'env'       => [],
        'exit_code' => 0,
        'stdout'    => '',
        'stderr'    => '',
    ];

    if (file_exists($jsonFile)) {
        $jsonSpec = json_decode(file_get_contents($jsonFile), true) ?: [];
        // Handle both legacy and operation-based formats
        if (isset($jsonSpec['operations'])) {
            // Operation-based format: extract from 'run' and 'wait' operations
            foreach ($jsonSpec['operations'] as $op) {
                if (($op['type'] ?? '') === 'run') {
                    $spec['args'] = $op['args'] ?? [];
                    $spec['dirs'] = $op['dirs'] ?? [];
                    $spec['env']  = $op['env'] ?? [];
                }
                if (($op['type'] ?? '') === 'wait') {
                    $spec['exit_code'] = $op['exit_code'] ?? 0;
                }
            }
        } else {
            // Legacy format
            $spec = array_merge($spec, $jsonSpec);
        }
    }

    // Build command line
    $cmd = [escapeshellarg($php), escapeshellarg($cliPath), '--wasi'];

    // Add preopen dirs
    foreach ($spec['dirs'] as $dir) {
        // The dir path is relative to the test's fixture directory
        // Create a temporary working copy of the fixture directory for tests that modify files
        $tmpDir = sys_get_temp_dir() . '/wasi-test-' . $testName . '-' . getmypid();
        if (is_dir($tmpDir)) {
            // Clean up from previous run
            shell_exec("rm -rf " . escapeshellarg($tmpDir));
        }
        // Create the fixture dir structure
        mkdir($tmpDir, 0777, true);
        // Copy fixture files if they exist in the source
        $srcFixture = $srcDir . '/' . $dir;
        if (is_dir($srcFixture)) {
            shell_exec("cp -R " . escapeshellarg($srcFixture) . "/* " . escapeshellarg($tmpDir) . "/ 2>/dev/null");
            shell_exec("cp -R " . escapeshellarg($srcFixture) . "/.* " . escapeshellarg($tmpDir) . "/ 2>/dev/null");
        }
        $cmd[] = '--dir=' . escapeshellarg($tmpDir);
    }

    // Add the wasm file
    $cmd[] = escapeshellarg($wasmFile);

    // Add test args
    foreach ($spec['args'] as $arg) {
        // Replace dir name with actual tmp path if it matches a preopened dir
        if (isset($tmpDir) && in_array($arg, $spec['dirs'])) {
            $cmd[] = escapeshellarg($tmpDir);
        } else {
            $cmd[] = escapeshellarg($arg);
        }
    }

    // Add env vars
    $envPrefix = '';
    foreach ($spec['env'] as $key => $value) {
        $envPrefix .= escapeshellarg("$key=$value") . ' ';
    }

    $fullCmd = ($envPrefix ? "env $envPrefix " : '') . implode(' ', $cmd) . ' 2>&1';

    // Execute
    $output   = [];
    $exitCode = 0;
    exec($fullCmd, $output, $exitCode);
    $outputStr = implode("\n", $output);

    // Clean up temp dir
    if (isset($tmpDir) && is_dir($tmpDir)) {
        shell_exec("rm -rf " . escapeshellarg($tmpDir));
    }
    unset($tmpDir);

    // Check results
    $expectedExit = $spec['exit_code'];
    $testPassed   = ($exitCode === $expectedExit);

    // Also check stdout/stderr if specified
    $notes = '';
    if ($spec['stdout'] !== '' && !str_contains($outputStr, $spec['stdout'])) {
        $testPassed = false;
        $notes = "stdout mismatch";
    }

    if ($testPassed) {
        $passed++;
        $status = 'PASS';
    } else {
        $failed++;
        $status = 'FAIL';
        if ($exitCode === 1 && str_contains($outputStr, 'Error')) {
            $errors++;
            $notes = $notes ?: trim(substr($outputStr, 0, 200));
        } else {
            $notes = $notes ?: "exit=$exitCode (expected=$expectedExit)";
            if ($outputStr) {
                $notes .= ': ' . trim(substr($outputStr, 0, 200));
            }
        }
    }

    $results[$testName] = [
        'status'    => $status,
        'exit_code' => $exitCode,
        'expected'  => $expectedExit,
        'output'    => $outputStr,
        'notes'     => $notes,
    ];

    if (!$jsonOut) {
        $icon = $status === 'PASS' ? '✅' : '❌';
        echo "$icon $testName";
        if ($verbose || $status === 'FAIL') {
            if ($notes) echo " — $notes";
        }
        echo "\n";
        if ($verbose && $outputStr) {
            foreach (explode("\n", $outputStr) as $line) {
                echo "   $line\n";
            }
        }
    }
}

// ---- Summary ----

if ($jsonOut) {
    echo json_encode([
        'total'   => $total,
        'passed'  => $passed,
        'failed'  => $failed,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n" . str_repeat('─', 50) . "\n";
    echo "Results: $passed/$total passed, $failed failed\n";

    if ($failed > 0) {
        echo "\nFailed tests:\n";
        foreach ($results as $name => $r) {
            if ($r['status'] === 'FAIL') {
                echo "  ❌ $name — {$r['notes']}\n";
            }
        }
    }
}

exit($failed > 0 ? 1 : 0);
