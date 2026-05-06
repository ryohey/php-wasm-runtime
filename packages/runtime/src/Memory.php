<?php

declare(strict_types=1);

namespace WasmRuntime;

/** WebAssembly linear memory (page = 65536 bytes). */
final class Memory
{
    public const PAGE_SIZE = 65536;
    public const MAX_PAGES = 65536;

    /** Raw byte buffer, lazily grown on access. Public for inline access in Executor. */
    public string $bytes = '';
    /** Tracks strlen($this->bytes) to avoid repeated strlen() calls. */
    public int $allocated = 0;
    /** Cached pages * PAGE_SIZE to avoid repeated multiplication in check(). */
    public int $limit = 0;
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
        $this->limit    = $minPages * self::PAGE_SIZE;
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
        $this->limit = $new * self::PAGE_SIZE;
        return $old;
    }

    /** Validate bounds and lazily zero-extend the byte buffer (used by bulk ops). */
    private function check(int $addr, int $len): void
    {
        if ($addr < 0 || $addr + $len > $this->limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        $needed = $addr + $len;
        if ($needed > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $needed - $this->allocated);
            $this->allocated = $needed;
        }
    }

    // ---- load: inline bounds check + unpack($fmt, $data, $offset) to avoid substr() ----

    public function loadI32(int $addr, int $align = 0): int
    {
        if ($addr < 0 || $addr + 4 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 4 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 4 - $this->allocated);
            $this->allocated = $addr + 4;
        }
        $v = unpack('V', $this->bytes, $addr)[1];
        return ($v & 0x80000000) ? ($v | (-1 << 32)) : $v;
    }

    public function loadU32(int $addr): int
    {
        if ($addr < 0 || $addr + 4 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 4 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 4 - $this->allocated);
            $this->allocated = $addr + 4;
        }
        return unpack('V', $this->bytes, $addr)[1];
    }

    public function loadI64(int $addr): int
    {
        if ($addr < 0 || $addr + 8 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 8 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 8 - $this->allocated);
            $this->allocated = $addr + 8;
        }
        $r = unpack('V2', $this->bytes, $addr);
        return ($r[2] << 32) | ($r[1] & 0xFFFFFFFF);
    }

    public function loadF32(int $addr): int|float
    {
        if ($addr < 0 || $addr + 4 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 4 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 4 - $this->allocated);
            $this->allocated = $addr + 4;
        }
        $bits = unpack('V', $this->bytes, $addr)[1];
        // Return NaN as int bit pattern to preserve payload
        if (($bits & 0x7FFFFFFF) > 0x7F800000) {
            return \WasmRuntime\WasmValue::mask32($bits);
        }
        return unpack('f', $this->bytes, $addr)[1];
    }

    public function loadF64(int $addr): float
    {
        if ($addr < 0 || $addr + 8 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 8 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 8 - $this->allocated);
            $this->allocated = $addr + 8;
        }
        return unpack('d', $this->bytes, $addr)[1];
    }

    public function loadI8s(int $addr): int
    {
        if ($addr < 0 || $addr + 1 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 1 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 1 - $this->allocated);
            $this->allocated = $addr + 1;
        }
        $b = ord($this->bytes[$addr]);
        return ($b & 0x80) ? ($b | (-1 << 8)) : $b;
    }

    public function loadI8u(int $addr): int
    {
        if ($addr < 0 || $addr + 1 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 1 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 1 - $this->allocated);
            $this->allocated = $addr + 1;
        }
        return ord($this->bytes[$addr]);
    }

    public function loadI16s(int $addr): int
    {
        if ($addr < 0 || $addr + 2 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 2 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 2 - $this->allocated);
            $this->allocated = $addr + 2;
        }
        $v = unpack('v', $this->bytes, $addr)[1];
        return ($v & 0x8000) ? ($v | (-1 << 16)) : $v;
    }

    public function loadI16u(int $addr): int
    {
        if ($addr < 0 || $addr + 2 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 2 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 2 - $this->allocated);
            $this->allocated = $addr + 2;
        }
        return unpack('v', $this->bytes, $addr)[1];
    }

    public function loadI32s(int $addr): int  // sign-extend for i64.load32_s
    {
        $v = $this->loadU32($addr);
        return ($v & 0x80000000) ? ($v | (-1 << 32)) : $v;
    }

    // ---- store: inline bounds check + direct byte writes (O(1)) ----

    public function storeI32(int $addr, int $v): void
    {
        if ($addr < 0 || $addr + 4 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 4 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 4 - $this->allocated);
            $this->allocated = $addr + 4;
        }
        $this->bytes[$addr]   = chr($v & 0xFF);
        $this->bytes[$addr+1] = chr(($v >> 8) & 0xFF);
        $this->bytes[$addr+2] = chr(($v >> 16) & 0xFF);
        $this->bytes[$addr+3] = chr(($v >> 24) & 0xFF);
    }

    public function storeI64(int $addr, int $v): void
    {
        if ($addr < 0 || $addr + 8 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 8 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 8 - $this->allocated);
            $this->allocated = $addr + 8;
        }
        $this->bytes[$addr]   = chr($v & 0xFF);
        $this->bytes[$addr+1] = chr(($v >> 8) & 0xFF);
        $this->bytes[$addr+2] = chr(($v >> 16) & 0xFF);
        $this->bytes[$addr+3] = chr(($v >> 24) & 0xFF);
        $this->bytes[$addr+4] = chr(($v >> 32) & 0xFF);
        $this->bytes[$addr+5] = chr(($v >> 40) & 0xFF);
        $this->bytes[$addr+6] = chr(($v >> 48) & 0xFF);
        $this->bytes[$addr+7] = chr(($v >> 56) & 0xFF);
    }

    public function storeF32(int $addr, int|float $v): void
    {
        if ($addr < 0 || $addr + 4 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 4 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 4 - $this->allocated);
            $this->allocated = $addr + 4;
        }
        $bits = is_int($v) ? $v : \WasmRuntime\WasmValue::f32Bits($v);
        $this->bytes[$addr]   = chr($bits & 0xFF);
        $this->bytes[$addr+1] = chr(($bits >> 8) & 0xFF);
        $this->bytes[$addr+2] = chr(($bits >> 16) & 0xFF);
        $this->bytes[$addr+3] = chr(($bits >> 24) & 0xFF);
    }

    public function storeF64(int $addr, float $v): void
    {
        if ($addr < 0 || $addr + 8 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 8 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 8 - $this->allocated);
            $this->allocated = $addr + 8;
        }
        $p = pack('d', $v);
        $this->bytes[$addr]   = $p[0];
        $this->bytes[$addr+1] = $p[1];
        $this->bytes[$addr+2] = $p[2];
        $this->bytes[$addr+3] = $p[3];
        $this->bytes[$addr+4] = $p[4];
        $this->bytes[$addr+5] = $p[5];
        $this->bytes[$addr+6] = $p[6];
        $this->bytes[$addr+7] = $p[7];
    }

    public function storeI8(int $addr, int $v): void
    {
        if ($addr < 0 || $addr + 1 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 1 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 1 - $this->allocated);
            $this->allocated = $addr + 1;
        }
        $this->bytes[$addr] = chr($v & 0xFF);
    }

    public function storeI16(int $addr, int $v): void
    {
        if ($addr < 0 || $addr + 2 > $this->limit) throw Trap::outOfBoundsMemoryAccess();
        if ($addr + 2 > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $addr + 2 - $this->allocated);
            $this->allocated = $addr + 2;
        }
        $this->bytes[$addr]   = chr($v & 0xFF);
        $this->bytes[$addr+1] = chr(($v >> 8) & 0xFF);
    }

    // ---- bulk operations (substr_replace is fine for large chunks) ----
    public function init(int $addr, string $data): void
    {
        $len = strlen($data);
        $this->check($addr, $len);
        // Use byte-by-byte in-place write to avoid substr_replace allocating a new
        // copy of the entire memory buffer (mmap overhead for buffers > ~1 MB).
        for ($k = 0; $k < $len; $k++) {
            $this->bytes[$addr + $k] = $data[$k];
        }
    }

    public function fill(int $addr, int $byte, int $n): void
    {
        if ($n < 0 || $addr < 0 || $addr + $n > $this->limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        $needed = $addr + $n;
        if ($needed > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $needed - $this->allocated);
            $this->allocated = $needed;
        }
        $c = chr($byte & 0xFF);
        for ($k = 0; $k < $n; $k++) {
            $this->bytes[$addr + $k] = $c;
        }
    }

    public function copy(int $dst, int $src, int $n): void
    {
        if ($n < 0 || $dst < 0 || $src < 0 || $dst + $n > $this->limit || $src + $n > $this->limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        $needed = max($dst + $n, $src + $n);
        if ($needed > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $needed - $this->allocated);
            $this->allocated = $needed;
        }
        // In-place byte-by-byte copy avoids substr_replace's full-buffer reallocation.
        // Overlap-safe: copy forward if dst < src, backward if dst > src.
        if ($dst <= $src || $dst >= $src + $n) {
            for ($k = 0; $k < $n; $k++) {
                $this->bytes[$dst + $k] = $this->bytes[$src + $k];
            }
        } else {
            for ($k = $n - 1; $k >= 0; $k--) {
                $this->bytes[$dst + $k] = $this->bytes[$src + $k];
            }
        }
    }

    public function initFromData(int $dst, string $data, int $src, int $n): void
    {
        $dataLen = strlen($data);
        if ($n < 0 || $src < 0 || $src + $n > $dataLen || $dst < 0 || $dst + $n > $this->limit) {
            throw Trap::outOfBoundsMemoryAccess();
        }
        if ($n === 0) return;
        $needed = $dst + $n;
        if ($needed > $this->allocated) {
            $this->bytes    .= str_repeat("\0", $needed - $this->allocated);
            $this->allocated = $needed;
        }
        for ($k = 0; $k < $n; $k++) {
            $this->bytes[$dst + $k] = $data[$src + $k];
        }
    }

    public function rawBytes(): string
    {
        return $this->bytes;
    }
}
