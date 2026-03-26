<?php

declare(strict_types=1);

namespace WasmRuntime;

/**
 * A fully-instantiated Wasm module.
 * Holds runtime state: memories, tables, globals, and the executor.
 */
final class Instance
{
    /** @var Memory[] */
    public array $memories = [];

    /** @var Table[] */
    public array $tables = [];

    /** Runtime global values (int|float indexed by absolute global index) */
    public array $globals = [];

    public readonly Executor $executor;

    public function __construct(public readonly Module $module)
    {
        $this->executor = new Executor($this);
    }

    /**
     * Instantiate a module with optional imports.
     *
     * $imports format:
     *   ['moduleName' => ['fieldName' => callable|Memory|Table|WasmValue|int|float]]
     *
     * @param  array<string,array<string,mixed>> $imports
     */
    public static function instantiate(Module $mod, array $imports = []): self
    {
        $inst = new self($mod);

        // ---- Process imports ----
        $impFuncIdx = 0;
        foreach ($mod->imports as $imp) {
            $modName   = $imp['module'];
            $fieldName = $imp['name'];
            $provided  = $imports[$modName][$fieldName] ?? null;

            switch ($imp['kind']) {
                case 'func':
                    if ($provided === null) {
                        $provided = static function (array $args): array { return []; };
                    }
                    $inst->executor->registerHostFunc($impFuncIdx, $provided);
                    $impFuncIdx++;
                    break;
                case 'memory':
                    if ($provided instanceof Memory) {
                        $inst->memories[] = $provided;
                    } else {
                        $inst->memories[] = new Memory($imp['min'], $imp['max'] ?? null);
                    }
                    break;
                case 'table':
                    if ($provided instanceof Table) {
                        $inst->tables[] = $provided;
                    } else {
                        $inst->tables[] = new Table($imp['min'], $imp['max'] ?? null);
                    }
                    break;
                case 'global':
                    if ($provided instanceof WasmValue) {
                        $inst->globals[] = $provided->value;
                    } else {
                        $inst->globals[] = $provided ?? 0;
                    }
                    break;
            }
        }

        // ---- Memories ----
        foreach ($mod->memories as $memDef) {
            $inst->memories[] = new Memory($memDef['min'], $memDef['max'] ?? null);
        }

        // ---- Tables ----
        foreach ($mod->tables as $tblDef) {
            $inst->tables[] = new Table($tblDef['min'], $tblDef['max'] ?? null);
        }

        // ---- Globals ----
        foreach ($mod->globals as $gDef) {
            $init = $gDef['init'];
            $inst->globals[] = ($init instanceof WasmValue) ? $init->value : $init;
        }

        // ---- Data segments ----
        foreach ($mod->dataSegments as $ds) {
            // Passive data segments have no memory index; they are initialized via memory.init
            if (!empty($ds['passive'])) {
                continue;
            }
            $memIdx = $ds['memIndex'];
            $offset = (int)(($ds['offset'] instanceof WasmValue) ? $ds['offset']->value : $ds['offset']);
            if (!isset($inst->memories[$memIdx])) {
                throw new WasmError("unknown memory $memIdx");
            }
            $inst->memories[$memIdx]->init($offset, $ds['bytes']);
        }

        // ---- Element segments ----
        foreach ($mod->elements as $es) {
            $tableIdx = $es['tableIndex'];
            $offset   = (int)(($es['offset'] instanceof WasmValue) ? $es['offset']->value : $es['offset']);
            if (isset($inst->tables[$tableIdx])) {
                foreach ($es['funcIndices'] as $i => $fi) {
                    $inst->tables[$tableIdx]->set($offset + $i, $fi);
                }
            }
        }

        // ---- Start function ----
        if ($mod->startFunc >= 0) {
            $inst->executor->invoke($mod->startFunc, []);
        }

        return $inst;
    }

    /**
     * Call an exported function by name.
     *
     * @param  WasmValue[] $args
     * @return WasmValue[]
     */
    public function callExport(string $name, array $args = []): array
    {
        $exp = $this->module->exports[$name] ?? throw new WasmError("No export '$name'");
        if ($exp['kind'] !== 'func') {
            throw new WasmError("Export '$name' is not a function");
        }
        return $this->executor->invoke($exp['index'], $args);
    }

    /** Get an exported global value */
    public function getExportedGlobal(string $name): WasmValue
    {
        $exp = $this->module->exports[$name] ?? throw new WasmError("No export '$name'");
        if ($exp['kind'] !== 'global') {
            throw new WasmError("Export '$name' is not a global");
        }
        $absIdx  = $exp['index'];
        $rawVal  = $this->globals[$absIdx];
        $gDef    = $absIdx < $this->module->importedGlobalCount
            ? $this->module->imports[$absIdx]
            : $this->module->globals[$absIdx - $this->module->importedGlobalCount];
        $gtype   = $gDef['globalType'] ?? $gDef['type'];
        return match ($gtype) {
            ValType::I32 => WasmValue::i32((int)$rawVal),
            ValType::I64 => WasmValue::i64((int)$rawVal),
            ValType::F32 => WasmValue::f32((float)$rawVal),
            ValType::F64 => WasmValue::f64((float)$rawVal),
            default      => WasmValue::i32((int)$rawVal),
        };
    }
}
