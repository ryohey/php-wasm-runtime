<?php

declare(strict_types=1);

namespace WasmRuntime;

/** Wasm module definition (decoded from WAT / binary) */
final class Module
{
    /** Optional module identifier ($name) from WAT source */
    public ?string $id = null;

    /** @var FuncType[] */
    public array $types = [];

    /**
     * Imported functions, memories, tables, globals.
     * Each: ['kind' => 'func'|'memory'|'table'|'global',
     *         'module' => string, 'name' => string,
     *         'typeIndex' => int (func), 'limits' => [min,max?] (mem/table),
     *         'globalType' => int (global), 'mutable' => bool (global)]
     * @var array<int,array>
     */
    public array $imports = [];

    /**
     * Function type-index references for non-imported functions.
     * @var int[]
     */
    public array $funcTypeIndices = [];

    /**
     * Function bodies (compiled instruction arrays).
     * Each entry: ['locals' => int[] (ValType per slot), 'code' => array (flat instructions)]
     * @var array<int,array>
     */
    public array $funcBodies = [];

    /**
     * Table definitions.
     * Each: ['type' => ValType::FUNCREF, 'min' => int, 'max' => ?int]
     * @var array<int,array>
     */
    public array $tables = [];

    /**
     * Memory definitions.
     * Each: ['min' => int (pages), 'max' => ?int]
     * @var array<int,array>
     */
    public array $memories = [];

    /**
     * Global definitions.
     * Each: ['type' => ValType, 'mutable' => bool, 'init' => WasmValue]
     * @var array<int,array>
     */
    public array $globals = [];

    /**
     * Exports: name -> ['kind' => 'func'|'table'|'memory'|'global', 'index' => int]
     * @var array<string,array>
     */
    public array $exports = [];

    /** Start function index (absolute), or -1 */
    public int $startFunc = -1;

    /**
     * Element segments.
     * Each: ['tableIndex' => int, 'offset' => WasmValue|array (init expr), 'funcIndices' => int[]]
     * @var array<int,array>
     */
    public array $elements = [];

    /**
     * Data segments.
     * Each: ['memIndex' => int, 'offset' => WasmValue|array, 'bytes' => string]
     * @var array<int,array>
     */
    public array $dataSegments = [];

    /** Number of imported functions (determines func index offset) */
    public int $importedFuncCount = 0;
    public int $importedTableCount = 0;
    public int $importedMemoryCount = 0;
    public int $importedGlobalCount = 0;

    public function totalFuncCount(): int
    {
        return $this->importedFuncCount + count($this->funcTypeIndices);
    }

    public function funcType(int $absIndex): FuncType
    {
        if ($absIndex < $this->importedFuncCount) {
            $imp = $this->imports[$absIndex];
            return $this->types[$imp['typeIndex']];
        }
        $localIndex = $absIndex - $this->importedFuncCount;
        return $this->types[$this->funcTypeIndices[$localIndex]];
    }
}
