<?php

declare(strict_types=1);

namespace WasmRuntime;

/** WebAssembly function-reference table */
final class Table
{
    /** @var mixed[] element values (null = uninitialized/null-ref, int = func index, or any ref) */
    private array $elements;
    private ?int $maxSize;

    public function __construct(int $minSize, ?int $maxSize = null, mixed $initVal = null)
    {
        // Guard against unreasonably large table sizes that would exhaust memory
        if ($minSize > 10_000_000 || $minSize < 0) {
            throw new Trap('table size exceeds implementation limit');
        }
        $this->elements = $minSize > 0 ? array_fill(0, $minSize, $initVal) : [];
        $this->maxSize  = $maxSize;
    }

    public function size(): int
    {
        return count($this->elements);
    }

    public function get(int $idx): mixed
    {
        if ($idx < 0 || $idx >= count($this->elements)) {
            throw Trap::outOfBoundsTableAccess();
        }
        return $this->elements[$idx];
    }

    public function set(int $idx, mixed $value): void
    {
        if ($idx < 0 || $idx >= count($this->elements)) {
            throw Trap::outOfBoundsTableAccess();
        }
        $this->elements[$idx] = $value;
    }

    public function grow(int $delta, mixed $initVal = null): int
    {
        $old = count($this->elements);
        $new = $old + $delta;
        if ($delta < 0 || ($this->maxSize !== null && $new > $this->maxSize)) {
            return -1;
        }
        for ($i = 0; $i < $delta; $i++) {
            $this->elements[] = $initVal;
        }
        return $old;
    }
}
