<?php

declare(strict_types=1);

namespace WasmRuntime;

/** WebAssembly linear memory (page = 65536 bytes) */
final class Memory
{
    public const PAGE_SIZE = 65536;
    public const MAX_PAGES = 65536;

    private string $bytes;
    private int $pages;
    private ?int $maxPages;

    public function __construct(int $minPages, ?int $maxPages = null)
    {
        $this->pages    = $minPages;
        $this->maxPages = $maxPages;
        $this->bytes    = str_repeat("\0", $minPages * self::PAGE_SIZE);
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
        $this->bytes  .= str_repeat("\0", $delta * self::PAGE_SIZE);
        $this->pages   = $new;
        return $old;
    }

    private function check(int $addr, int $bytes): void
    {
        if ($addr < 0 || $addr + $bytes > strlen($this->bytes)) {
            throw Trap::outOfBoundsMemoryAccess();
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

    public function loadF32(int $addr): float
    {
        $this->check($addr, 4);
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

    public function storeF32(int $addr, float $v): void
    {
        $this->check($addr, 4);
        $this->bytes = substr_replace($this->bytes, pack('f', $v), $addr, 4);
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

    public function rawBytes(): string
    {
        return $this->bytes;
    }
}
