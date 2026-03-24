<?php

declare(strict_types=1);

namespace WasmRuntime;

final class FuncType
{
    public function __construct(
        /** @var int[] ValType constants */
        public readonly array $params,
        /** @var int[] ValType constants */
        public readonly array $results,
    ) {}

    public function equals(FuncType $other): bool
    {
        return $this->params === $other->params && $this->results === $other->results;
    }
}
