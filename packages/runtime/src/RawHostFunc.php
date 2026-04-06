<?php

declare(strict_types=1);

namespace WasmRuntime;

final class RawHostFunc
{
    private readonly \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    public function invoke(array $stack, int $base, int $count): mixed
    {
        return ($this->handler)($stack, $base, $count);
    }

    public function invokeArgs(array $args): mixed
    {
        return ($this->handler)($args, 0, count($args));
    }
}