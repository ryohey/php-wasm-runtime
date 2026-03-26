<?php

declare(strict_types=1);

namespace WasmRuntime;

/** WebAssembly linear memory (page = 65536 bytes). Allocated lazily. */
final class Memory
{
    public const PAGE_SIZE = 65536;
    public const MAX_PAGES = 65536;

    /** Actually-allocated bytes (lazily grown on first access). */
    private string $bytes = '';
    private int $pages;
    private ?int $maxPages;

    public function __construct(int $minPages, ?int $maxPages = null)
    {
        if ($minPages > self::MAX_PAGES || ($maxPages !== null && $maxPages > self::MAX_PAGES)) {
            throw new WasmError("memory size must be at most 65536 pages (4GiB)");
        }
        if ($maxPages !== null && $minPages > $maxPages) {
            throw new WasmError("size minimum must not be greater than maximum");
        }
        $this->pages    = $minPages;
        $this->maxPages = $maxPages;
        // Lazy: bytes are zero-initialized on demand in ensureAllocated()
    }

    public function size(): int
    {
        return $this->pages;
    }

    public function grow(int $delta): int
    {
        $old = $this->pages;
        $new = $old + $delta;
        if ($delta < 0 || $new > self::MAX_PAGES) {
            return -1;
        }
        if ($this->maxPages !== null && $new > $this->maxPages) {
            return -1;
        }
        $this->pages = $new;
        // Actual byte allocation happens lazily in ensureAllocated()
        return $old;
    }

    /**
     * Ensure $this->bytes is at least $upTo bytes long (zero-padded).
     * Also validates $addr + $len is within the logical page boundary.
     */
    private function check(int $addr, int $len): void
    {
        $limit = $this->pages * self::PAGE_SIZE;
        if ($addr < 0 || $addr + $len > $limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        $needed = $addr + $len;
        if ($needed > strlen($this->bytes)) {
            $this->bytes .= str_repeat("\0", $needed - strlen($this->bytes));
        }
    }

    // ---- load ----
    public function loadI32(int $addr, int $align = 0): int
    {
        $this->check($addr, 4);
        $v = unpack('V', substr($this->bytes, $addr, 4))[1];
        return ($v & 0x80000000) ? ($v | (-1 << 32)) : $v;
    }

    public function loadU32(int $addr): int
    {
        $this->check($addr, 4);
        return unpack('V', substr($this->bytes, $addr, 4))[1];
    }

    public function loadI64(int $addr): int
    {
        $this->check($addr, 8);
        $lo = unpack('V', substr($this->bytes, $addr, 4))[1];
        $hi = unpack('V', substr($this->bytes, $addr + 4, 4))[1];
        return ($hi << 32) | $lo;
    }

    public function loadF32(int $addr): int|float
    {
        $this->check($addr, 4);
        $bits = unpack('V', substr($this->bytes, $addr, 4))[1];
        // Return NaN as int bit pattern to preserve payload
        if (($bits & 0x7FFFFFFF) > 0x7F800000) {
            return \WasmRuntime\WasmValue::mask32($bits);
        }
        return unpack('f', substr($this->bytes, $addr, 4))[1];
    }

    public function loadF64(int $addr): float
    {
        $this->check($addr, 8);
        return unpack('d', substr($this->bytes, $addr, 8))[1];
    }

    public function loadI8s(int $addr): int
    {
        $this->check($addr, 1);
        $b = ord($this->bytes[$addr]);
        return ($b & 0x80) ? ($b | (-1 << 8)) : $b;
    }

    public function loadI8u(int $addr): int
    {
        $this->check($addr, 1);
        return ord($this->bytes[$addr]);
    }

    public function loadI16s(int $addr): int
    {
        $this->check($addr, 2);
        $v = unpack('v', substr($this->bytes, $addr, 2))[1];
        return ($v & 0x8000) ? ($v | (-1 << 16)) : $v;
    }

    public function loadI16u(int $addr): int
    {
        $this->check($addr, 2);
        return unpack('v', substr($this->bytes, $addr, 2))[1];
    }

    public function loadI32s(int $addr): int  // sign-extend for i64.load32_s
    {
        $v = $this->loadU32($addr);
        return ($v & 0x80000000) ? ($v | (-1 << 32)) : $v;
    }

    // ---- store ----
    public function storeI32(int $addr, int $v): void
    {
        $this->check($addr, 4);
        $this->bytes = substr_replace($this->bytes, pack('V', $v & 0xFFFFFFFF), $addr, 4);
    }

    public function storeI64(int $addr, int $v): void
    {
        $this->check($addr, 8);
        $lo = $v & 0xFFFFFFFF;
        $hi = ($v >> 32) & 0xFFFFFFFF;
        $this->bytes = substr_replace($this->bytes, pack('VV', $lo, $hi), $addr, 8);
    }

    public function storeF32(int $addr, int|float $v): void
    {
        $this->check($addr, 4);
        // f32 NaN values are stored as int (bit pattern); use directly
        if (is_int($v)) {
            $this->bytes = substr_replace($this->bytes, pack('V', $v), $addr, 4);
        } else {
            $this->bytes = substr_replace($this->bytes, pack('V', \WasmRuntime\WasmValue::f32Bits($v)), $addr, 4);
        }
    }

    public function storeF64(int $addr, float $v): void
    {
        $this->check($addr, 8);
        $this->bytes = substr_replace($this->bytes, pack('d', $v), $addr, 8);
    }

    public function storeI8(int $addr, int $v): void
    {
        $this->check($addr, 1);
        $this->bytes[$addr] = chr($v & 0xFF);
    }

    public function storeI16(int $addr, int $v): void
    {
        $this->check($addr, 2);
        $this->bytes = substr_replace($this->bytes, pack('v', $v & 0xFFFF), $addr, 2);
    }

    public function init(int $addr, string $data): void
    {
        $len = strlen($data);
        $this->check($addr, $len);
        $this->bytes = substr_replace($this->bytes, $data, $addr, $len);
    }

    public function fill(int $addr, int $byte, int $n): void
    {
        $limit = $this->pages * self::PAGE_SIZE;
        if ($n < 0 || $addr < 0 || $addr + $n > $limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        // Ensure buffer covers the target range (lazy allocation)
        $needed = $addr + $n;
        if ($needed > strlen($this->bytes)) {
            $this->bytes .= str_repeat("\0", $needed - strlen($this->bytes));
        }
        $this->bytes = substr_replace($this->bytes, str_repeat(chr($byte & 0xFF), $n), $addr, $n);
    }

    public function copy(int $dst, int $src, int $n): void
    {
        $limit = $this->pages * self::PAGE_SIZE;
        if ($n < 0 || $dst < 0 || $src < 0 || $dst + $n > $limit || $src + $n > $limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        // Ensure bytes are allocated
        $needed = max($dst + $n, $src + $n);
        if ($needed > strlen($this->bytes)) {
            $this->bytes .= str_repeat("\0", $needed - strlen($this->bytes));
        }
        $chunk = substr($this->bytes, $src, $n);
        $this->bytes = substr_replace($this->bytes, $chunk, $dst, $n);
    }

    public function initFromData(int $dst, string $data, int $src, int $n): void
    {
        $dataLen = strlen($data);
        $limit = $this->pages * self::PAGE_SIZE;
        if ($n < 0 || $src < 0 || $src + $n > $dataLen || $dst < 0 || $dst + $n > $limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        $chunk = substr($data, $src, $n);
        $this->bytes = substr_replace($this->bytes, $chunk, $dst, $n);
    }

    public function rawBytes(): string
    {
        return $this->bytes;
    }
}
