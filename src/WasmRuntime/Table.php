<?php

declare(strict_types=1);

namespace WasmRuntime;

/** WebAssembly function-reference table */
final class Table
{
    /** @var (int|null)[] absolute function indices, null = uninitialized */
    private array $elements;
    private ?int $maxSize;

    public function __construct(int $minSize, ?int $maxSize = null)
    {
        $this->elements = array_fill(0, $minSize, null);
        $this->maxSize  = $maxSize;
    }

    public function size(): int
    {
        return count($this->elements);
    }

    public function get(int $idx): ?int
    {
        if ($idx < 0 || $idx >= count($this->elements)) {
            throw Trap::outOfBoundsTableAccess();
        }
        return $this->elements[$idx];
    }

    public function set(int $idx, ?int $funcIndex): void
    {
        if ($idx < 0 || $idx >= count($this->elements)) {
            throw Trap::outOfBoundsTableAccess();
        }
        $this->elements[$idx] = $funcIndex;
    }

    public function grow(int $delta, ?int $initVal = null): int
    {
        $old = count($this->elements);
        $new = $old + $delta;
        if ($this->maxSize !== null && $new > $this->maxSize) {
            return -1;
        }
        for ($i = 0; $i < $delta; $i++) {
            $this->elements[] = $initVal;
        }
        return $old;
    }
}
