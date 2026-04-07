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

    /**
     * Flat cache: absolute function index → FuncType.
     * Built by buildIndex() after all sections are decoded.
     * @var FuncType[]
     */
    public array $funcTypeFlat = [];

    /**
     * Flat cache: absolute function index → param count.
     * Built by buildIndex() — avoids count() calls on every CALL instruction.
     * @var int[]
     */
    public array $paramCounts = [];

    /**
     * Flat cache: type index → param count.
     * Built by buildIndex() — avoids count() calls on every CALL_INDIRECT instruction.
     * @var int[]
     */
    public array $typeParamCounts = [];

    /**
     * Flat cache: absolute function index → result count.
     * @var int[]
     */
    public array $resultCounts = [];

    /**
     * Flat cache: absolute function index → type index (into $types[]).
     * Used by CALL_INDIRECT for fast int comparison before structural equals().
     * @var int[]
     */
    public array $funcTypeIndicesFlat = [];

    /**
     * Flat cache: absolute function index → funcBody (includes 'code', 'codeLen', 'localDefaults').
     * Only populated for local (non-import) functions.
     * @var array[]
     */
    public array $funcBodiesFlat = [];

    /**
     * Parallel arrays split out of funcBodiesFlat for faster int-indexed access in the hot loop.
     * Eliminates string-key hash lookup on every CALL/CALL_INDIRECT.
     * @var array[] funcIdx → code array
     */
    public array $funcCode = [];
    /** @var int[] funcIdx → codeLen */
    public array $funcCodeLen = [];
    /** @var array[] funcIdx → localDefaults array */
    public array $funcLocalDefaults = [];

    public function totalFuncCount(): int
    {
        return $this->importedFuncCount + count($this->funcTypeIndices);
    }

    public function funcType(int $absIndex): FuncType
    {
        return $this->funcTypeFlat[$absIndex]
            ?? ($absIndex < $this->importedFuncCount
                ? $this->types[$this->imports[$absIndex]['typeIndex']]
                : $this->types[$this->funcTypeIndices[$absIndex - $this->importedFuncCount]]);
    }

    /**
     * Pre-compute funcTypeFlat and paramCounts after all sections are decoded.
     * Called by Decoder once the module is fully built.
     */
    public function buildIndex(): void
    {
        $flat   = [];
        $counts = [];
        $typeIdxFlat = [];
        $impIdx = 0;
        foreach ($this->imports as $imp) {
            if ($imp['kind'] === 'func') {
                $ft              = $this->types[$imp['typeIndex']];
                $flat[$impIdx]   = $ft;
                $counts[$impIdx] = count($ft->params);
                $typeIdxFlat[$impIdx] = $imp['typeIndex'];
                $impIdx++;
            }
        }
        $resCounts = []; $bodiesFlat = [];
        $fCode = []; $fLen = []; $fLD = [];
        foreach ($this->funcTypeIndices as $i => $typeIdx) {
            $ft     = $this->types[$typeIdx];
            $absIdx = $this->importedFuncCount + $i;
            $flat[$absIdx]        = $ft;
            $counts[$absIdx]      = count($ft->params);
            $resCounts[$absIdx]   = count($ft->results);
            $typeIdxFlat[$absIdx] = $typeIdx;
            // Precompute code length into body and build flat body index
            if (isset($this->funcBodies[$i])) {
                $codeLen = count($this->funcBodies[$i]['code']);
                $this->funcBodies[$i]['codeLen'] = $codeLen;
                $bodiesFlat[$absIdx] = $this->funcBodies[$i];
                $fCode[$absIdx] = $this->funcBodies[$i]['code'];
                $fLen[$absIdx]  = $codeLen;
                    $ld = $this->funcBodies[$i]['localDefaults'];
                    // Store as integer count when all defaults are zero-equivalent
                    // (int 0, float 0.0, or null) — Executor uses fast while-loop for these.
                    $allZero = true;
                    foreach ($ld as $v) { if ($v !== 0 && $v !== 0.0 && $v !== null) { $allZero = false; break; } }
                    $fLD[$absIdx] = $allZero ? count($ld) : $ld;
            }
        }
        // Imports: only need resultCounts
        $impIdx2 = 0;
        foreach ($this->imports as $imp) {
            if ($imp['kind'] === 'func') { $resCounts[$impIdx2] = count($this->types[$imp['typeIndex']]->results); $impIdx2++; }
        }
        $this->funcTypeFlat        = $flat;
        $this->paramCounts         = $counts;
        $this->resultCounts        = $resCounts;
        $this->funcBodiesFlat      = $bodiesFlat;
        $this->funcTypeIndicesFlat = $typeIdxFlat;
        $this->funcCode            = $fCode;
        $this->funcCodeLen         = $fLen;
        $this->funcLocalDefaults   = $fLD;

        // Pre-compute param counts per type index for CALL_INDIRECT
        $typeCounts = [];
        foreach ($this->types as $i => $ft) {
            $typeCounts[$i] = count($ft->params);
        }
        $this->typeParamCounts = $typeCounts;
    }
}
