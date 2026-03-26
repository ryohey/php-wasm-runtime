<?php

declare(strict_types=1);

namespace WasmRuntime\Binary;

use WasmRuntime\WasmError;

/**
 * Low-level reader for WebAssembly binary format.
 * Wraps a byte string with position tracking and LEB128 decoding.
 */
final class BinaryReader
{
    private int $pos = 0;
    private int $len;

    public function __construct(private readonly string $data)
    {
        $this->len = strlen($data);
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function remaining(): int
    {
        return $this->len - $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= $this->len;
    }

    public function readByte(): int
    {
        if ($this->pos >= $this->len) {
            throw new WasmError('unexpected end of section or function');
        }
        return ord($this->data[$this->pos++]);
    }

    public function peekByte(): int
    {
        if ($this->pos >= $this->len) {
            throw new WasmError('unexpected end of section or function');
        }
        return ord($this->data[$this->pos]);
    }

    public function readBytes(int $n): string
    {
        if ($this->pos + $n > $this->len) {
            throw new WasmError('unexpected end of section or function');
        }
        $result = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $result;
    }

    /** Read unsigned LEB128 (up to 32 bits) */
    public function readU32(): int
    {
        $result = 0;
        $shift  = 0;
        do {
            $byte    = $this->readByte();
            $result |= ($byte & 0x7F) << $shift;
            $shift  += 7;
            if ($shift > 35) {
                throw new WasmError('integer representation too long');
            }
        } while ($byte & 0x80);
        return $result;
    }

    /** Read signed LEB128 (32-bit) */
    public function readS32(): int
    {
        $result = 0;
        $shift  = 0;
        do {
            $byte    = $this->readByte();
            $result |= ($byte & 0x7F) << $shift;
            $shift  += 7;
        } while ($byte & 0x80);
        // Sign extend
        if ($shift < 32 && ($byte & 0x40)) {
            $result |= (-1 << $shift);
        }
        // Mask to 32 bits for proper sign
        return ($result << 32) >> 32;
    }

    /** Read signed LEB128 (33-bit, used for block types) */
    public function readS33(): int
    {
        $result = 0;
        $shift  = 0;
        do {
            $byte    = $this->readByte();
            $result |= ($byte & 0x7F) << $shift;
            $shift  += 7;
        } while ($byte & 0x80);
        if ($shift < 33 && ($byte & 0x40)) {
            $result |= (-1 << $shift);
        }
        return $result;
    }

    /** Read signed LEB128 (64-bit) */
    public function readS64(): int
    {
        $result = 0;
        $shift  = 0;
        do {
            $byte    = $this->readByte();
            $result |= ($byte & 0x7F) << $shift;
            $shift  += 7;
        } while ($byte & 0x80);
        if ($shift < 64 && ($byte & 0x40)) {
            $result |= (-1 << $shift);
        }
        return $result;
    }

    /** Read f32 (4 bytes little-endian IEEE 754) */
    public function readF32(): int|float
    {
        $bytes = $this->readBytes(4);
        $bits = unpack('V', $bytes)[1];
        // Return NaN as int bit pattern to preserve payload
        if (($bits & 0x7FFFFFFF) > 0x7F800000) {
            return \WasmRuntime\WasmValue::mask32($bits);
        }
        return unpack('f', $bytes)[1];
    }

    /** Read f64 (8 bytes little-endian IEEE 754) */
    public function readF64(): float
    {
        $bytes = $this->readBytes(8);
        return unpack('d', $bytes)[1];
    }

    /** Read a name (UTF-8 string with LEB128 length prefix) */
    public function readName(): string
    {
        $len = $this->readU32();
        return $this->readBytes($len);
    }

    /** Read a vector of items using a callback */
    public function readVec(callable $readItem): array
    {
        $count = $this->readU32();
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $readItem();
        }
        return $items;
    }

    /** Skip $n bytes */
    public function skip(int $n): void
    {
        if ($this->pos + $n > $this->len) {
            throw new WasmError('unexpected end of section or function');
        }
        $this->pos += $n;
    }

    /** Create a sub-reader for a given byte range */
    public function subReader(int $length): self
    {
        $sub = new self($this->readBytes($length));
        return $sub;
    }
}
