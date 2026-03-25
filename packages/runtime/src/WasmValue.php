<?php

declare(strict_types=1);

namespace WasmRuntime;

final class WasmValue
{
    public function __construct(
        public readonly int $type,
        /** int for i32/i64, float for f32/f64 */
        public readonly int|float $value,
    ) {}

    public static function i32(int $v): self
    {
        return new self(ValType::I32, self::mask32($v));
    }

    public static function i64(int $v): self
    {
        return new self(ValType::I64, $v);
    }

    public static function f32(float $v): self
    {
        return new self(ValType::F32, self::canonF32($v));
    }

    public static function f64(float $v): self
    {
        return new self(ValType::F64, $v);
    }

    /** Signed 32-bit integer (sign-extend from bit 31) */
    public static function mask32(int $v): int
    {
        $v = $v & 0xFFFFFFFF;
        return ($v & 0x80000000) ? ($v | (-1 << 32)) : $v;
    }

    /** Unsigned 32-bit view */
    public static function u32(int $v): int
    {
        return $v & 0xFFFFFFFF;
    }

    /** Round float to f32 precision via pack/unpack, preserving NaN payloads. */
    public static function canonF32(float $v): float
    {
        if (is_nan($v)) {
            // Explicit bit-level f64 → f32 narrowing to preserve NaN payloads.
            // PHP's pack('f',...) may canonicalise NaN payloads on some platforms.
            $w    = unpack('V2', pack('d', $v));
            $hi32 = $w[2]; // high 32 bits of f64 (little-endian layout)
            $lo32 = $w[1]; // low  32 bits of f64
            $sign     = $hi32 & 0x80000000;
            // f64 mantissa bits [51:29] map to f32 mantissa bits [22:0]
            $mant_hi  = $hi32 & 0xFFFFF;
            $mant_f32 = (($mant_hi << 3) | (($lo32 >> 29) & 0x7)) & 0x7FFFFF;
            if ($mant_f32 === 0) $mant_f32 = 0x400000; // keep non-infinite
            $bits32 = $sign | 0x7F800000 | $mant_f32;
            return (float)unpack('f', pack('V', (int)$bits32))[1];
        }
        $packed = pack('f', $v);
        return (float)unpack('f', $packed)[1];
    }

    public function equals(WasmValue $other): bool
    {
        if ($this->type !== $other->type) {
            return false;
        }
        return match ($this->type) {
            ValType::I32, ValType::I64 => $this->value === $other->value,
            ValType::F32, ValType::F64 => $this->floatEquals($other),
            default => false,
        };
    }

    private function floatEquals(WasmValue $other): bool
    {
        $a = $this->value;
        $b = $other->value;
        if (is_nan((float)$a) && is_nan((float)$b)) {
            return true;
        }
        return $a === $b;
    }

    public function __toString(): string
    {
        return ValType::name($this->type) . '(' . $this->value . ')';
    }
}
