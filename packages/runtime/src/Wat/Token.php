<?php

declare(strict_types=1);

namespace WasmRuntime\Wat;

final class Token
{
    public const LPAREN  = 'LPAREN';
    public const RPAREN  = 'RPAREN';
    public const KEYWORD = 'KEYWORD';   // module, func, i32.add, ...
    public const ID      = 'ID';        // $name
    public const INT     = 'INT';       // 42, 0xFF, -1
    public const FLOAT   = 'FLOAT';     // 1.5, nan, inf, nan:0x1
    public const STRING  = 'STRING';    // "hello"
    public const EOF     = 'EOF';

    public function __construct(
        public readonly string $type,
        public readonly string|int|float $value,
        public readonly int $line,
        /** Original hex-float string for tokens that may lose precision via f64 */
        public readonly ?string $rawString = null,
    ) {}

    public function __toString(): string
    {
        return "{$this->type}({$this->value})@{$this->line}";
    }
}
