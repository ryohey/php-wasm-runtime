<?php

declare(strict_types=1);

namespace WasmRuntime;

final class ValType
{
    public const I32      = 0x7F;
    public const I64      = 0x7E;
    public const F32      = 0x7D;
    public const F64      = 0x7C;
    public const FUNCREF  = 0x70;
    public const EXTERNREF = 0x6F;

    public static function name(int $t): string
    {
        return match ($t) {
            self::I32      => 'i32',
            self::I64      => 'i64',
            self::F32      => 'f32',
            self::F64      => 'f64',
            self::FUNCREF  => 'funcref',
            self::EXTERNREF => 'externref',
            default        => "unknown(0x" . dechex($t) . ")",
        };
    }

    public static function fromString(string $s): int
    {
        return match ($s) {
            'i32'       => self::I32,
            'i64'       => self::I64,
            'f32'       => self::F32,
            'f64'       => self::F64,
            'funcref'   => self::FUNCREF,
            'externref' => self::EXTERNREF,
            default     => throw new \InvalidArgumentException("Unknown value type: $s"),
        };
    }
}
