<?php

declare(strict_types=1);

namespace WasmRuntime\Tests\Wasi;

use PHPUnit\Framework\TestCase;
use WasmRuntime\Instance;
use WasmRuntime\Wasi\Wasi;
use WasmRuntime\Wasi\WasiExitException;
use WasmRuntime\Wat\Parser;

/**
 * Integration tests for the WASI (wasi_snapshot_preview1) implementation.
 *
 * Each test loads a WAT fixture from tests/fixtures/, runs it with WASI
 * enabled, and asserts on stdout content and/or exit code.
 */
final class WasiTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Instantiate and run a WAT file with WASI enabled.
     *
     * @param  string[] $argv  WASI argv (index 0 is program name; added automatically)
     * @param  array<string,string> $env  environment variables
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runWat(string $name, array $argv = [], array $env = []): array
    {
        $path = $this->fixturesDir . '/' . $name;
        $this->assertFileExists($path, "Fixture not found: $name");

        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');

        $wasi = new Wasi(
            args: array_merge([$name], $argv),
            env: $env,
            stdout: $stdout,
            stderr: $stderr,
        );

        $module   = (new Parser())->parseModule((string)file_get_contents($path));
        $instance = Instance::instantiate($module, $wasi->getImports());
        $wasi->bindInstance($instance);

        $exitCode = 0;
        try {
            $instance->callExport('_start', []);
        } catch (WasiExitException $e) {
            $exitCode = $e->exitCode;
        }

        rewind($stdout);
        rewind($stderr);

        return [
            'stdout'   => (string)stream_get_contents($stdout),
            'stderr'   => (string)stream_get_contents($stderr),
            'exitCode' => $exitCode,
        ];
    }

    // -------------------------------------------------------------------------
    // fd_write / proc_exit
    // -------------------------------------------------------------------------

    public function testHelloWorld(): void
    {
        $result = $this->runWat('hello.wat');

        $this->assertSame("Hello, WASI!\n", $result['stdout']);
        $this->assertSame('', $result['stderr']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function testProcExitNonZero(): void
    {
        $result = $this->runWat('proc_exit.wat');

        $this->assertSame(42, $result['exitCode']);
    }

    // -------------------------------------------------------------------------
    // args_get / args_sizes_get
    // -------------------------------------------------------------------------

    public function testArgcNoExtraArgs(): void
    {
        $result = $this->runWat('args.wat');
        $this->assertSame("argc: 1\n", $result['stdout']); // only argv[0]
        $this->assertSame(0, $result['exitCode']);
    }

    public function testArgcWithArgs(): void
    {
        $result = $this->runWat('args.wat', ['foo', 'bar', 'baz']);
        $this->assertSame("argc: 4\n", $result['stdout']); // argv[0] + 3 args
        $this->assertSame(0, $result['exitCode']);
    }
}
