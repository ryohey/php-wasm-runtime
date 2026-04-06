<?php

declare(strict_types=1);

namespace WasmRuntime;

final class RawHostFunc
{
    public readonly \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }
}