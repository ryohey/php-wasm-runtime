<?php

declare(strict_types=1);

namespace WasmRuntime\Wasi;

/**
 * Thrown by the WASI proc_exit syscall.
 * Catch this in the host to retrieve the exit code.
 */
final class WasiExitException extends \RuntimeException
{
    public function __construct(public readonly int $exitCode)
    {
        parent::__construct("WASI proc_exit called with code $exitCode");
    }
}
