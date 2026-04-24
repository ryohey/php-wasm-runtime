<?php

declare(strict_types=1);

namespace WasmRuntime\Binary;

use WasmRuntime\{FuncType, Module, Op, ValType, WasmError, WasmValue};

/**
 * Decodes a WebAssembly binary (.wasm) into a Module.
 *
 * Reads all standard sections, decodes instructions into flat bytecode
 * with pre-computed IP targets for control flow (matching Executor expectations).
 */
final class Decoder
{
    /** @var FuncType[] */
    private array $types = [];

    private Module $mod;

    /** funcIdx → (resultCount - paramCount) for $sd tracking in CALL */
    private array $funcNetStack = [];
    /** typeIdx → (resultCount - paramCount) for $sd tracking in CALL_INDIRECT */
    private array $typeNetStack = [];

    /**
     * Decode a WASM binary string into a Module.
     */
    public function decode(string $bytes): Module
    {
        $r = new BinaryReader($bytes);
        $this->mod = new Module();

        // ---- Header ----
        $magic = $r->readBytes(4);
        if ($magic !== "\x00asm") {
            throw new WasmError('magic header not detected');
        }
        $version = unpack('V', $r->readBytes(4))[1];
        if ($version !== 1) {
            throw new WasmError("unknown binary version: $version");
        }

        // ---- Sections ----
        while (!$r->eof()) {
            $sectionId  = $r->readByte();
            $sectionLen = $r->readU32();
            $sub        = $r->subReader($sectionLen);

            match ($sectionId) {
                0  => $this->decodeCustomSection($sub),
                1  => $this->decodeTypeSection($sub),
                2  => $this->decodeImportSection($sub),
                3  => $this->decodeFunctionSection($sub),
                4  => $this->decodeTableSection($sub),
                5  => $this->decodeMemorySection($sub),
                6  => $this->decodeGlobalSection($sub),
                7  => $this->decodeExportSection($sub),
                8  => $this->decodeStartSection($sub),
                9  => $this->decodeElementSection($sub),
                10 => $this->decodeCodeSection($sub),
                11 => $this->decodeDataSection($sub),
                12 => $this->decodeDataCountSection($sub),
                default => null, // skip unknown sections
            };
        }

        $this->mod->buildIndex();
        return $this->mod;
    }

    private static function sectionName(int $sectionId): string
    {
        return match ($sectionId) {
            0 => 'custom',
            1 => 'type',
            2 => 'import',
            3 => 'function',
            4 => 'table',
            5 => 'memory',
            6 => 'global',
            7 => 'export',
            8 => 'start',
            9 => 'element',
            10 => 'code',
            11 => 'data',
            12 => 'data_count',
            default => 'unknown_' . $sectionId,
        };
    }

    // =========================================================================
    // Section decoders
    // =========================================================================

    private function decodeCustomSection(BinaryReader $r): void
    {
        // Custom sections are ignored (name section, etc.)
    }

    private function decodeTypeSection(BinaryReader $r): void
    {
        $this->types = $r->readVec(function () use ($r) {
            $form = $r->readByte();
            if ($form !== 0x60) {
                throw new WasmError("expected functype (0x60), got 0x" . dechex($form));
            }
            $params  = $r->readVec(fn() => $this->readValType($r));
            $results = $r->readVec(fn() => $this->readValType($r));
            return new FuncType($params, $results);
        });
        $this->mod->types = $this->types;
    }

    private function decodeImportSection(BinaryReader $r): void
    {
        $imports = $r->readVec(function () use ($r) {
            $module = $r->readName();
            $name   = $r->readName();
            $kind   = $r->readByte();

            return match ($kind) {
                0x00 => $this->decodeImportFunc($module, $name, $r),
                0x01 => $this->decodeImportTable($module, $name, $r),
                0x02 => $this->decodeImportMemory($module, $name, $r),
                0x03 => $this->decodeImportGlobal($module, $name, $r),
                default => throw new WasmError("unknown import kind: $kind"),
            };
        });
        $this->mod->imports = $imports;

        // Count imported items by kind
        foreach ($imports as $imp) {
            match ($imp['kind']) {
                'func'   => $this->mod->importedFuncCount++,
                'table'  => $this->mod->importedTableCount++,
                'memory' => $this->mod->importedMemoryCount++,
                'global' => $this->mod->importedGlobalCount++,
            };
        }
    }

    private function decodeImportFunc(string $module, string $name, BinaryReader $r): array
    {
        $typeIdx = $r->readU32();
        return [
            'kind'      => 'func',
            'module'    => $module,
            'name'      => $name,
            'typeIndex' => $typeIdx,
        ];
    }

    private function decodeImportTable(string $module, string $name, BinaryReader $r): array
    {
        $elemType = $this->readRefType($r);
        [$min, $max] = $this->decodeLimits($r);
        return [
            'kind'   => 'table',
            'module' => $module,
            'name'   => $name,
            'type'   => $elemType,
            'min'    => $min,
            'max'    => $max,
        ];
    }

    private function decodeImportMemory(string $module, string $name, BinaryReader $r): array
    {
        [$min, $max] = $this->decodeLimits($r);
        return [
            'kind'   => 'memory',
            'module' => $module,
            'name'   => $name,
            'min'    => $min,
            'max'    => $max,
        ];
    }

    private function decodeImportGlobal(string $module, string $name, BinaryReader $r): array
    {
        $valType = $this->readValType($r);
        $mutable = $r->readByte() === 1;
        return [
            'kind'       => 'global',
            'module'     => $module,
            'name'       => $name,
            'globalType' => $valType,
            'mutable'    => $mutable,
        ];
    }

    private function decodeFunctionSection(BinaryReader $r): void
    {
        $this->mod->funcTypeIndices = $r->readVec(fn() => $r->readU32());
    }

    private function decodeTableSection(BinaryReader $r): void
    {
        $this->mod->tables = $r->readVec(function () use ($r) {
            $byte = $r->peekByte();
            // GC proposal: table with init expression (0x40 0x00 prefix)
            if ($byte === 0x40) {
                $r->readByte(); // 0x40
                $r->readByte(); // 0x00
                $elemType = $this->readRefType($r);
                [$min, $max] = $this->decodeLimits($r);
                $init = $this->decodeConstExpr($r);
                return ['type' => $elemType, 'min' => $min, 'max' => $max, 'init' => $init];
            }
            $elemType = $this->readRefType($r);
            [$min, $max] = $this->decodeLimits($r);
            return ['type' => $elemType, 'min' => $min, 'max' => $max];
        });
    }

    private function decodeMemorySection(BinaryReader $r): void
    {
        $this->mod->memories = $r->readVec(function () use ($r) {
            [$min, $max] = $this->decodeLimits($r);
            return ['min' => $min, 'max' => $max];
        });
    }

    private function decodeGlobalSection(BinaryReader $r): void
    {
        $this->mod->globals = $r->readVec(function () use ($r) {
            $valType = $this->readValType($r);
            $mutable = $r->readByte() === 1;
            $init    = $this->decodeConstExpr($r);
            return ['type' => $valType, 'mutable' => $mutable, 'init' => $init];
        });
    }

    private function decodeExportSection(BinaryReader $r): void
    {
        $exports = $r->readVec(function () use ($r) {
            $name  = $r->readName();
            $kind  = $r->readByte();
            $index = $r->readU32();
            $kindStr = match ($kind) {
                0 => 'func',
                1 => 'table',
                2 => 'memory',
                3 => 'global',
                default => throw new WasmError("unknown export kind: $kind"),
            };
            return ['name' => $name, 'kind' => $kindStr, 'index' => $index];
        });

        // Check for duplicate export names and build associative array
        foreach ($exports as $exp) {
            $n = $exp['name'];
            if (isset($this->mod->exports[$n])) {
                throw new WasmError("duplicate export name \"$n\"");
            }
            $this->mod->exports[$n] = ['kind' => $exp['kind'], 'index' => $exp['index']];
        }
    }

    private function decodeStartSection(BinaryReader $r): void
    {
        $this->mod->startFunc = $r->readU32();
    }

    private function decodeElementSection(BinaryReader $r): void
    {
        $this->mod->elements = $r->readVec(function () use ($r) {
            $flags = $r->readU32();

            // Element segment encoding is complex with many flag combinations
            // See: https://webassembly.github.io/spec/core/binary/modules.html#element-section
            return match ($flags) {
                0 => $this->decodeElemActive0($r),
                1 => $this->decodeElemPassiveOrDeclarative($r, passive: true),
                2 => $this->decodeElemActive2($r),
                3 => $this->decodeElemPassiveOrDeclarative($r, passive: true, hasElemKind: true),
                4 => $this->decodeElemActive4($r),
                5 => $this->decodeElemPassive5($r),
                6 => $this->decodeElemActive6($r),
                7 => $this->decodeElemPassive7($r),
                default => throw new WasmError("unsupported elem flags: $flags"),
            };
        });
    }

    /** Flags=0: active, table 0, offset expr, vec(funcidx) */
    private function decodeElemActive0(BinaryReader $r): array
    {
        $offset      = $this->decodeConstExpr($r);
        $funcIndices = $r->readVec(fn() => $r->readU32());
        return ['tableIndex' => 0, 'offset' => $offset, 'funcIndices' => $funcIndices];
    }

    /** Flags=1,3: passive/declarative, elemkind, vec(funcidx) */
    private function decodeElemPassiveOrDeclarative(BinaryReader $r, bool $passive, bool $hasElemKind = false): array
    {
        if ($hasElemKind) {
            $r->readByte(); // elemkind (0x00 = funcref)
        }
        $funcIndices = $r->readVec(fn() => $r->readU32());
        return [
            'tableIndex'  => 0,
            'offset'      => WasmValue::i32(0),
            'funcIndices' => $funcIndices,
            'passive'     => true,
        ];
    }

    /** Flags=2: active, tableidx, offset expr, elemkind, vec(funcidx) */
    private function decodeElemActive2(BinaryReader $r): array
    {
        $tableIdx    = $r->readU32();
        $offset      = $this->decodeConstExpr($r);
        $r->readByte(); // elemkind
        $funcIndices = $r->readVec(fn() => $r->readU32());
        return ['tableIndex' => $tableIdx, 'offset' => $offset, 'funcIndices' => $funcIndices];
    }

    /** Flags=4: active, table 0, offset expr, vec(expr) - expression elements */
    private function decodeElemActive4(BinaryReader $r): array
    {
        $offset      = $this->decodeConstExpr($r);
        $funcIndices = $r->readVec(function () use ($r) {
            return $this->decodeElemExpr($r);
        });
        return ['tableIndex' => 0, 'offset' => $offset, 'funcIndices' => $funcIndices];
    }

    /** Flags=5: passive, reftype, vec(expr) */
    private function decodeElemPassive5(BinaryReader $r): array
    {
        $this->readRefType($r); // reftype (may be multi-byte for GC)
        $funcIndices = $r->readVec(function () use ($r) {
            return $this->decodeElemExpr($r);
        });
        return [
            'tableIndex'  => 0,
            'offset'      => WasmValue::i32(0),
            'funcIndices' => $funcIndices,
            'passive'     => true,
        ];
    }

    /** Flags=6: active, tableidx, offset expr, reftype, vec(expr) */
    private function decodeElemActive6(BinaryReader $r): array
    {
        $tableIdx = $r->readU32();
        $offset   = $this->decodeConstExpr($r);
        $this->readRefType($r); // reftype (may be multi-byte for GC)
        $funcIndices = $r->readVec(function () use ($r) {
            return $this->decodeElemExpr($r);
        });
        return ['tableIndex' => $tableIdx, 'offset' => $offset, 'funcIndices' => $funcIndices];
    }

    /** Flags=7: passive/declarative, reftype, vec(expr) */
    private function decodeElemPassive7(BinaryReader $r): array
    {
        $this->readRefType($r); // reftype (may be multi-byte for GC)
        $funcIndices = $r->readVec(function () use ($r) {
            return $this->decodeElemExpr($r);
        });
        return [
            'tableIndex'  => 0,
            'offset'      => WasmValue::i32(0),
            'funcIndices' => $funcIndices,
            'passive'     => true,
        ];
    }

    /**
     * Decode an element expression (ref.func idx | ref.null | global.get idx).
     * Returns a func index (int) or -1 for null.
     */
    private function decodeElemExpr(BinaryReader $r): int
    {
        $opcode = $r->readByte();
        if ($opcode === 0xD2) {
            // ref.func
            $idx = $r->readU32();
            $r->readByte(); // 0x0B end
            return $idx;
        } elseif ($opcode === 0xD0) {
            // ref.null
            $this->readHeapType($r); // heap type (can be LEB128 for GC proposal)
            $r->readByte(); // 0x0B end
            return -1; // null reference
        } elseif ($opcode === 0x23) {
            // global.get — skip for now, return -1 as placeholder
            $r->readU32(); // global index
            $r->readByte(); // 0x0B end
            return -1;
        }
        // Unknown element expr: skip until end marker
        while (!$r->eof()) {
            if ($r->readByte() === 0x0B) break;
        }
        return -1;
    }

    /**
     * Read a heap type (reftype for GC proposal).
     * Can be a single byte (funcref=0x70, externref=0x6F, etc.) or a signed LEB128 type index.
     */
    private function readHeapType(BinaryReader $r): int
    {
        $byte = $r->peekByte();
        // Standard ref types are single bytes >= 0x60
        if ($byte >= 0x60) {
            return $r->readByte();
        }
        // GC proposal: signed LEB128 type index
        return $r->readS33();
    }

    /**
     * Read a value type. Handles standard types (i32..f64, funcref, externref)
     * and GC proposal encoded ref types (0x63/0x64 + heaptype).
     */
    private function readValType(BinaryReader $r): int
    {
        $byte = $r->peekByte();
        if ($byte === 0x63 || $byte === 0x64) {
            return $this->readRefType($r);
        }
        return $r->readByte();
    }

    /**
     * Read a reference type. Can be a simple reftype (0x70, 0x6F) or
     * a GC proposal encoded ref type (0x63/0x64 + heaptype).
     */
    private function readRefType(BinaryReader $r): int
    {
        $byte = $r->peekByte();
        // Standard ref types
        if ($byte === 0x70 || $byte === 0x6F) {
            return $r->readByte();
        }
        // GC proposal: (ref null heaptype) = 0x63, (ref heaptype) = 0x64
        if ($byte === 0x63 || $byte === 0x64) {
            $r->readByte(); // 0x63 or 0x64
            $heapType = $this->readHeapType($r);
            // Map to ValType::FUNCREF for function types
            return ValType::FUNCREF;
        }
        // Fallback: read as single byte
        return $r->readByte();
    }

    /**
     * Pre-compute per-function and per-type net stack effects for $sd tracking.
     * Must be called after imports + function sections are decoded but before code section.
     */
    private function buildFuncNetStack(): void
    {
        $this->funcNetStack = [];
        $this->typeNetStack = [];
        // Imported functions first
        foreach ($this->mod->imports as $imp) {
            if ($imp['kind'] === 'func') {
                $ft = $this->types[$imp['typeIndex']];
                $this->funcNetStack[] = count($ft->results) - count($ft->params);
            }
        }
        // Local functions
        foreach ($this->mod->funcTypeIndices as $typeIdx) {
            $ft = $this->types[$typeIdx];
            $this->funcNetStack[] = count($ft->results) - count($ft->params);
        }
        // Per type index
        foreach ($this->types as $idx => $ft) {
            $this->typeNetStack[$idx] = count($ft->results) - count($ft->params);
        }
    }

    private function decodeCodeSection(BinaryReader $r): void
    {
        $this->buildFuncNetStack();
        $bodies = $r->readVec(function () use ($r) {
            $bodySize = $r->readU32();
            $bodySub  = $r->subReader($bodySize);
            return $this->decodeFuncBody($bodySub);
        });
        $this->mod->funcBodies = $bodies;
    }

    private function decodeDataSection(BinaryReader $r): void
    {
        $this->mod->dataSegments = $r->readVec(function () use ($r) {
            $flags = $r->readU32();
            if ($flags === 0) {
                // Active, memory 0
                $offset = $this->decodeConstExpr($r);
                $bytes  = $r->readBytes($r->readU32());
                return ['memIndex' => 0, 'offset' => $offset, 'bytes' => $bytes];
            } elseif ($flags === 1) {
                // Passive
                $bytes = $r->readBytes($r->readU32());
                return ['memIndex' => 0, 'offset' => WasmValue::i32(0), 'bytes' => $bytes, 'passive' => true];
            } elseif ($flags === 2) {
                // Active, explicit memory index
                $memIdx = $r->readU32();
                $offset = $this->decodeConstExpr($r);
                $bytes  = $r->readBytes($r->readU32());
                return ['memIndex' => $memIdx, 'offset' => $offset, 'bytes' => $bytes];
            }
            throw new WasmError("unsupported data segment flags: $flags");
        });
    }

    private function decodeDataCountSection(BinaryReader $r): void
    {
        // Data count section just declares the number of data segments (for validation).
        // We don't need to store it; the data section handles actual segments.
        $r->readU32();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function decodeLimits(BinaryReader $r): array
    {
        $hasMax = $r->readByte();
        $min    = $r->readU32();
        $max    = $hasMax ? $r->readU32() : null;
        return [$min, $max];
    }

    /**
     * Decode a constant expression (init_expr).
     * Returns a WasmValue for simple cases.
     * For expressions containing global.get, returns an array of operations
     * that must be evaluated at instantiation time (when global values are known).
     * Handles extended const expressions (i32.add, i32.sub, i32.mul, i64.add, etc.)
     */
    private function decodeConstExpr(BinaryReader $r): WasmValue|array
    {
        $ops = [];
        $hasGlobalGet = false;

        while (true) {
            $opcode = $r->readByte();

            if ($opcode === 0x0B) {
                break;
            }

            match ($opcode) {
                0x41 => $ops[] = [0x41, $r->readS32()],
                0x42 => $ops[] = [0x42, $r->readS64()],
                0x43 => $ops[] = [0x43, $this->decodeConstF32($r)],
                0x44 => $ops[] = [0x44, WasmValue::f64($r->readF64())],
                0x23 => (function() use ($r, &$ops, &$hasGlobalGet) {
                    $ops[] = [0x23, $r->readU32()];
                    $hasGlobalGet = true;
                })(),
                0xD0 => $ops[] = [0xD0, $this->decodeConstRefNull($r)],
                0xD2 => $ops[] = [0xD2, $this->decodeConstRefFunc($r)],
                0x6A => $ops[] = [0x6A],  // i32.add
                0x6B => $ops[] = [0x6B],  // i32.sub
                0x6C => $ops[] = [0x6C],  // i32.mul
                0x7C => $ops[] = [0x7C],  // i64.add
                0x7D => $ops[] = [0x7D],  // i64.sub
                0x7E => $ops[] = [0x7E],  // i64.mul
                0x01 => null, // nop
                default => throw new WasmError("unsupported const expr opcode: 0x" . dechex($opcode)),
            };
        }

        // Simple case: no global.get, evaluate immediately
        if (!$hasGlobalGet) {
            return $this->evalConstOps($ops, []);
        }

        // Contains global.get: return deferred expression for instantiation-time eval
        return ['__constExpr' => true, 'ops' => $ops];
    }

    /**
     * Evaluate a list of constant expression operations.
     * $globals is the array of already-resolved global values (used for global.get).
     */
    public static function evalConstOps(array $ops, array $globals): WasmValue
    {
        $stack = [];
        foreach ($ops as $op) {
            match ($op[0]) {
                0x41 => $stack[] = WasmValue::i32($op[1]),          // i32.const
                0x42 => $stack[] = WasmValue::i64($op[1]),          // i64.const
                0x43 => $stack[] = $op[1],                          // f32.const
                0x44 => $stack[] = $op[1],                          // f64.const
                0x23 => $stack[] = self::resolveGlobalGetForConst($op[1], $globals), // global.get
                0xD0 => $stack[] = $op[1],                          // ref.null
                0xD2 => $stack[] = $op[1],                          // ref.func
                0x6A => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a + $b)), // i32.add
                0x6B => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a - $b)), // i32.sub
                0x6C => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a * $b)), // i32.mul
                0x7C => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a + $b), // i64.add
                0x7D => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a - $b), // i64.sub
                0x7E => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a * $b), // i64.mul
                default => null,
            };
        }
        return $stack[0] ?? WasmValue::i32(0);
    }

    private static function resolveGlobalGetForConst(int $idx, array $globals): WasmValue
    {
        $val = $globals[$idx] ?? 0;
        // Wrap raw value in WasmValue (assume i32 if we don't know the type)
        if ($val instanceof WasmValue) {
            return $val;
        }
        return is_float($val) ? WasmValue::f64($val) : WasmValue::i32((int)$val);
    }

    private static function constBinOp(array &$stack, int $type, callable $op): void
    {
        $b = array_pop($stack);
        $a = array_pop($stack);
        $result = $op((int)$a->value, (int)$b->value);
        $stack[] = $type === ValType::I32 ? WasmValue::i32($result) : WasmValue::i64($result);
    }

    private function evalConstGlobalGet(BinaryReader $r): WasmValue|array
    {
        $idx = $r->readU32();
        return ['op' => 'global.get', 'index' => $idx];
    }

    private function decodeConstF32(BinaryReader $r): WasmValue
    {
        $v = $r->readF32();
        if (is_int($v)) {
            // NaN stored as int bits
            return new WasmValue(ValType::F32, unpack('f', pack('V', $v))[1]);
        }
        return WasmValue::f32($v);
    }

    private function decodeConstRefNull(BinaryReader $r): WasmValue
    {
        $refType = $this->readHeapType($r);
        // Map heap types to ValType constants
        $valType = match ($refType) {
            0x70, 0x63 => ValType::FUNCREF,    // funcref, func
            0x6F, 0x6E => ValType::EXTERNREF,  // externref, extern
            default => ValType::FUNCREF,         // default to funcref for GC types
        };
        // Use -1 as sentinel for "null ref" to distinguish from ref.func 0
        return new WasmValue($valType, -1);
    }

    private function decodeConstRefFunc(BinaryReader $r): WasmValue
    {
        $funcIdx = $r->readU32();
        return new WasmValue(ValType::FUNCREF, $funcIdx);
    }

    // =========================================================================
    // Function body decoding
    // =========================================================================

    private function decodeFuncBody(BinaryReader $r): array
    {
        // Decode locals
        $locals = [];
        $localDeclCount = $r->readU32();
        for ($i = 0; $i < $localDeclCount; $i++) {
            $count   = $r->readU32();
            $valType = $this->readValType($r);
            for ($j = 0; $j < $count; $j++) {
                $locals[] = $valType;
            }
        }

        // Pre-compute default values for local slots (non-arg locals)
        $localDefaults = [];
        foreach ($locals as $lt) {
            $localDefaults[] = match ($lt) {
                ValType::F32, ValType::F64           => 0.0,
                ValType::FUNCREF, ValType::EXTERNREF => null,
                default                              => 0,
            };
        }

        // Decode instructions into flat bytecode with pre-computed IP targets
        $code = $this->decodeExpr($r);

        return ['locals' => $locals, 'localDefaults' => $localDefaults, 'code' => $code];
    }

    /**
     * Decode an expression (sequence of instructions terminated by end).
     * Uses a fixup stack for control flow IP resolution.
     *
     * Flat bytecode layout (matching Executor expectations):
     *
     *   block:  ['block', ?FuncType, endIp]
     *   loop:   ['loop', ?FuncType, contIp, endIp]
     *   if:     ['if', ?FuncType, elseIp, endIp]
     *   else:   ['else', endIp]
     *   end:    ['end']
     */
    /**
     * Emit precomputed branch immediates [targetIp, spDelta, rCnt] for a branch at depth $brDepth.
     * sdAfterBr = static stack depth AFTER consuming the branch condition (for BR_IF/BRIF peepholes)
     *             or the current $sd (for unconditional BR).
     * For forward refs (block/if), -1 is stored and patched at END time via frame['patches'].
     * For backward refs (loop), contIp is already known.
     */
    private function emitBranchImms(array &$code, array &$controlStack, int $brDepth, int $sdAfterBr): void
    {
        $frameIdx = count($controlStack) - 1 - $brDepth;
        $frame    = $controlStack[$frameIdx];
        $rForBr   = ($frame['kind'] === 'loop') ? $frame['p'] : $frame['r'];
        $spDelta  = ($frame['sd'] - $frame['p']) + $rForBr - $sdAfterBr;
        if ($frame['kind'] !== 'loop') {
            $controlStack[$frameIdx]['patches'][] = count($code);
            $code[] = -1; // forward ref placeholder; patched at END
        } else {
            $code[] = $frame['contIp']; // loop: backward ref, already known
        }
        $code[] = $spDelta;
        $code[] = $rForBr;
    }

    /**
     * Decode expression into flat bytecode stream with precomputed branch targets.
     *
     * $sd (static stack depth) tracks the value-stack depth from function entry (starts at 0).
     * Control frames carry 'sd' (depth at frame entry) so that spDelta can be computed at
     * decode time, eliminating the runtime label stack entirely.
     *
     * Op::BLOCK and Op::LOOP are NOT emitted — they are purely decode-time bookkeeping.
     * Op::IF_ [elseIp, endIp] and Op::ELSE_ [endIp] are still emitted; Op::END is eliminated entirely.
     * BR → SB_BR_PRECOMP [targetIp, spDelta, rCnt]
     * BR_IF → SB_BRIF_PRECOMP [targetIp, spDelta, rCnt]  (or SB_BRIF_PRECOMP_ESC for escapes)
     * BRIF peepholes: format changed from [depth] → [targetIp, spDelta, rCnt]
     */
    private function decodeExpr(BinaryReader $r): array
    {
        $code = [];
        $sd   = 0; // static stack depth (value stack, above function locals)

        // Control stack entries — associative arrays:
        //   block: ['kind'=>'block', 'sd'=>$sd, 'p'=>$btp, 'r'=>$btr, 'patches'=>[]]
        //   loop:  ['kind'=>'loop',  'sd'=>$sd, 'p'=>$btp, 'r'=>$btr, 'contIp'=>$ip, 'patches'=>[]]
        //   if:    ['kind'=>'if',    'sd'=>$sd, 'p'=>$btp, 'r'=>$btr, 'ip'=>$ifIp,   'patches'=>[]]
        //           sd for if = $sd AFTER popping condition (so formula is uniform)
        $controlStack = [];

        while (!$r->eof()) {
            $opcode = $r->readByte();

            if ($opcode === 0x0B) {
                // end
                if (empty($controlStack)) {
                    break; // terminal END eliminated — loop exits when $ip >= $len
                }
                $frame  = array_pop($controlStack);
                $endIp  = count($code); // first instruction AFTER this block (no END emitted)
                // Patch all forward-reference BRs that target this block/if
                foreach ($frame['patches'] as $patchIdx) {
                    $code[$patchIdx] = $endIp; // targetIp = first instruction after block
                }
                if ($frame['kind'] === 'if') {
                    $this->fixupIf($code, $frame, $endIp);
                    // Op::END eliminated — Executor uses $ip = $endIp directly (no +1)
                }
                // block/loop/if: no END emitted — dispatch eliminated
                // Restore $sd to what it should be after the block exits
                $sd = $frame['sd'] - $frame['p'] + $frame['r'];
                continue;
            }

            if ($opcode === 0x05) {
                // else
                if (empty($controlStack)) {
                    throw new WasmError('else without matching if');
                }
                $topIdx = count($controlStack) - 1;
                $elseIp = count($code);
                $controlStack[$topIdx]['elseIp'] = $elseIp;
                // Patch IF_/SB_LGET_IF_'s falseTargetIp to first instruction of else body ($elseIp + 2)
                $code[$controlStack[$topIdx]['falseSlot']] = $elseIp + 2;
                $code[] = Op::ELSE_;
                $code[] = -1; // endIp placeholder (filled by fixupIf at END)
                // Reset $sd to the if-body entry state for the else body
                $sd = $controlStack[$topIdx]['sd'];
                continue;
            }

            // ---- Inline decodeInstruction ----
            switch ($opcode) {
                // ---- Control flow ----
                case 0x00: $code[] = Op::UNREACHABLE; break;
                case 0x01: break; // NOP — skip, no-op needs no dispatch slot

                case 0x02: // block — no opcode emitted; purely decode-time bookkeeping
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; $btr = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; $btr = 1; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; $btr = $bt ? count($bt->results) : 0; } }
                    $controlStack[] = ['kind'=>'block','sd'=>$sd,'p'=>$btp,'r'=>$btr,'patches'=>[]];
                    break;

                case 0x03: // loop — no opcode emitted
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; $btr = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; $btr = 1; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; $btr = $bt ? count($bt->results) : 0; } }
                    $controlStack[] = ['kind'=>'loop','sd'=>$sd,'p'=>$btp,'r'=>$btr,'contIp'=>count($code),'patches'=>[]];
                    break;

                case 0x04: // if
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; $btr = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; $btr = 1; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; $btr = $bt ? count($bt->results) : 0; } }
                    $sd--;  // condition is popped by IF_
                    $cLenIf = count($code);
                    // Peephole priority (longest match first):
                    //   SB_LGET_ICONST+I32EQ/NE+IF_ > SB_LGET_LGET+I32EQ+IF_
                    //   > LGET+I32EQZ+IF_ > I32EQZ+IF_ > LGET+IF_ > IF_
                    if ($cLenIf >= 4 && $code[$cLenIf - 4] === Op::SB_LGET_ICONST
                            && ($code[$cLenIf - 1] === Op::I32_EQ  || $code[$cLenIf - 1] === Op::I32_NE
                             || $code[$cLenIf - 1] === Op::I32_LT_S || $code[$cLenIf - 1] === Op::I32_GT_S)) {
                        // SB_LGET_ICONST $x $c + I32_EQ/NE/LT_S/GT_S + IF_ → fused [x, c, falseTargetIp]
                        $ifIp = $cLenIf - 4;
                        $__last = $code[$cLenIf - 1];
                        $code[$ifIp] = $__last === Op::I32_EQ  ? Op::SB_LGET_ICONST_I32EQ_IF_
                                     : ($__last === Op::I32_NE  ? Op::SB_LGET_ICONST_I32NE_IF_
                                     : ($__last === Op::I32_LT_S ? Op::SB_LGET_ICONST_I32LTS_IF_
                                                                  : Op::SB_LGET_ICONST_I32GTS_IF_));
                        $code[$cLenIf - 1] = -1; // overwrite comparison slot with falseTargetIp
                        $falseSlot = $cLenIf - 1;
                    } elseif ($cLenIf >= 4 && $code[$cLenIf - 4] === Op::SB_LGET_LGET
                            && ($code[$cLenIf - 1] === Op::I32_EQ || $code[$cLenIf - 1] === Op::I32_NE)) {
                        // SB_LGET_LGET $a $b + I32_EQ/NE + IF_ → fused [a, b, falseTargetIp]
                        $ifIp = $cLenIf - 4;
                        $code[$ifIp] = ($code[$cLenIf - 1] === Op::I32_EQ)
                            ? Op::SB_LGET_LGET_I32EQ_IF_
                            : Op::SB_LGET_LGET_I32NE_IF_;
                        $code[$cLenIf - 1] = -1; // overwrite I32_EQ/NE slot with falseTargetIp
                        $falseSlot = $cLenIf - 1;
                    } elseif ($cLenIf >= 1 && $code[$cLenIf - 1] === Op::I32_EQZ) {
                        if ($cLenIf >= 3 && $code[$cLenIf - 3] === Op::LOCAL_GET) {
                            // LGET $x + I32EQZ + IF_ → SB_LGET_I32EQZ_IF_ [x, falseTargetIp]
                            // Branch to falseTarget when local[x] != 0 (eqz would give 0 → if branches)
                            $ifIp = $cLenIf - 3;
                            $code[$ifIp] = Op::SB_LGET_I32EQZ_IF_; // [localIdx already at +1, add falseTargetIp]
                            $code[$cLenIf - 1] = -1;                 // overwrite I32_EQZ with falseTargetIp
                            $falseSlot = $cLenIf - 1;
                        } else {
                            // I32EQZ + IF_ → SB_I32EQZ_IF_ [falseTargetIp]
                            // Branch to falseTarget when TOS != 0 (eqz would give 0 → if branches)
                            $ifIp = $cLenIf - 1;
                            $code[$ifIp] = Op::SB_I32EQZ_IF_; // overwrite I32_EQZ opcode
                            $code[$cLenIf] = -1;               // falseTargetIp placeholder
                            $falseSlot = $cLenIf;
                        }
                    } elseif ($cLenIf >= 2 && $code[$cLenIf - 2] === Op::LOCAL_GET) {
                        // LGET $x + IF_ → SB_LGET_IF_ [x, falseTargetIp]
                        $ifIp = $cLenIf - 2;
                        $code[$ifIp] = Op::SB_LGET_IF_; // [localIdx already at +1, add falseTargetIp]
                        $code[$cLenIf] = -1;             // falseTargetIp placeholder
                        $falseSlot = $cLenIf;
                    } else {
                        $ifIp = $cLenIf;
                        $code[$ifIp] = Op::IF_;   // IF_ [falseTargetIp]
                        $code[$ifIp + 1] = -1;    // falseTargetIp placeholder
                        $falseSlot = $ifIp + 1;
                    }
                    // frame.sd = $sd AFTER condition pop, so spDelta formula is uniform
                    $controlStack[] = ['kind'=>'if','sd'=>$sd,'p'=>$btp,'r'=>$btr,'ip'=>$ifIp,'falseSlot'=>$falseSlot,'patches'=>[]];
                    break;

                // ---- Branch ----
                case 0x0C: { // br — precomputed
                    $brDepth = $r->readU32();
                    $csLen   = count($controlStack);
                    if ($brDepth >= $csLen) {
                        $code[] = Op::RETURN_; // escape = function return
                    } else {
                        $code[] = Op::SB_BR_PRECOMP;
                        $this->emitBranchImms($code, $controlStack, $brDepth, $sd);
                    }
                    break;
                }
                case 0x0D: { // br_if — precomputed
                    $brDepth   = $r->readU32();
                    $csLen     = count($controlStack);
                    $sdAfterBr = $sd - 1; // br_if pops condition
                    if ($brDepth >= $csLen) {
                        $code[] = Op::SB_BRIF_PRECOMP_ESC;
                    } else {
                        $code[] = Op::SB_BRIF_PRECOMP;
                        $this->emitBranchImms($code, $controlStack, $brDepth, $sdAfterBr);
                    }
                    $sd--; // condition popped (fall-through path)
                    break;
                }

                case 0x0E: { // br_table — precomputed pairs
                    $labels   = $r->readVec(fn() => $r->readU32());
                    $default  = $r->readU32();
                    $csLen    = count($controlStack);
                    $sdPop    = $sd - 1; // after popping index
                    // Check void (r=0) for all non-escaping targets
                    $_allVoid = true;
                    foreach (array_merge($labels, [$default]) as $_d) {
                        $_fi = $csLen - 1 - $_d;
                        if ($_fi < 0 || $controlStack[$_fi]['r'] !== 0) { $_allVoid = false; break; }
                    }
                    // Choose opcode: SB_BR_TABLE_VOID (no result copy) or BR_TABLE (general)
                    $_defaultFrame = ($csLen - 1 - $default >= 0) ? $controlStack[$csLen - 1 - $default] : null;
                    $_rCnt = $_defaultFrame ? ($_allVoid ? 0 : $_defaultFrame['r']) : 0;
                    $code[] = $_allVoid ? Op::SB_BR_TABLE_VOID : Op::BR_TABLE;
                    $code[] = count($labels); // label count
                    if (!$_allVoid) $code[] = $_rCnt; // result count stored once for BR_TABLE
                    foreach (array_merge($labels, [$default]) as $_d) {
                        $_fi = $csLen - 1 - $_d;
                        if ($_fi < 0) {
                            $code[] = -1; $code[] = 0; // escape: targetIp=-1, spDelta=0
                        } else {
                            $_fr = $controlStack[$_fi];
                            $_r  = ($_fr['kind'] === 'loop') ? $_fr['p'] : $_fr['r'];
                            $_spD = ($_fr['sd'] - $_fr['p']) + $_r - $sdPop;
                            if ($_fr['kind'] !== 'loop') {
                                $controlStack[$_fi]['patches'][] = count($code);
                                $code[] = -1; // forward ref
                            } else {
                                $code[] = $_fr['contIp'];
                            }
                            $code[] = $_spD;
                        }
                    }
                    $sd--; // index popped
                    break;
                }

                case 0x0F: $code[] = Op::RETURN_; break; // $sd doesn't matter after return

                // ---- Calls ----
                case 0x10: { $fIdx=$r->readU32(); $code[]=Op::CALL; $code[]=$fIdx; $sd += $this->funcNetStack[$fIdx] ?? 0; break; }

                case 0x11: { // call_indirect
                    $typeIdx=$r->readU32(); $tableIdx=$r->readU32();
                    $code[]=Op::CALL_INDIRECT; $code[]=$typeIdx; $code[]=$tableIdx;
                    $sd += ($this->typeNetStack[$typeIdx] ?? 0) - 1; // -1 for popped table index
                    break;
                }

                case 0x12: $code[] = Op::RETURN_CALL; $code[] = $r->readU32(); break;

                case 0x13: { // return_call_indirect
                    $typeIdx=$r->readU32(); $tableIdx=$r->readU32();
                    $code[]=Op::RETURN_CALL_INDIRECT; $code[]=$typeIdx; $code[]=$tableIdx;
                    break;
                }

                // ---- Stack ----
                case 0x1A: $code[] = Op::DROP; $sd--; break;
                case 0x1B: $code[] = Op::SELECT; $sd -= 2; break;
                case 0x1C: // select (typed)
                    $r->readVec(fn() => $this->readValType($r));
                    $code[] = Op::SELECT; $sd -= 2;
                    break;

                // ---- Variables ----
                case 0x20: { // LOCAL_GET — peephole for common successors
                    $localIdx = $r->readU32();
                    if (!$r->eof()) {
                        $nb = $r->peekByte();
                        if ($nb === 0x20) { // LOCAL_GET follows
                            $r->readByte(); $localIdx2=$r->readU32();
                            if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32STORE;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();/* net 0 */break;}
                            if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32LOAD;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();$sd+=2;break;}
                            if(!$r->eof()&&$r->peekByte()===0x6A){$r->readByte();$code[]=Op::SB_LGET_LGET_I32ADD;$code[]=$localIdx;$code[]=$localIdx2;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x47){$r->readByte(); // I32_NE
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDll=$r->readU32();$csLll=count($controlStack);
                                    if($brDll>=$csLll){$code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_NE;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_LGET_I32NE_BRIF;$code[]=$localIdx;$code[]=$localIdx2;$this->emitBranchImms($code,$controlStack,$brDll,$sd);break;}
                                $code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_NE;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x46){$r->readByte(); // I32_EQ
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlq=$r->readU32();$csLlq=count($controlStack);
                                    if($brDlq>=$csLlq){$code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_EQ;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_LGET_I32EQ_BRIF;$code[]=$localIdx;$code[]=$localIdx2;$this->emitBranchImms($code,$controlStack,$brDlq,$sd);break;}
                                $code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_EQ;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x48){$r->readByte(); // I32_LT_S
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDls=$r->readU32();$csLls=count($controlStack);
                                    if($brDls>=$csLls){$code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_LT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_LGET_I32LTS_BRIF;$code[]=$localIdx;$code[]=$localIdx2;$this->emitBranchImms($code,$controlStack,$brDls,$sd);break;}
                                $code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_LT_S;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x4A){$r->readByte(); // I32_GT_S
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDgs=$r->readU32();$csLgs=count($controlStack);
                                    if($brDgs>=$csLgs){$code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_GT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_LGET_I32GTS_BRIF;$code[]=$localIdx;$code[]=$localIdx2;$this->emitBranchImms($code,$controlStack,$brDgs,$sd);break;}
                                $code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$code[]=Op::I32_GT_S;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x6B){$r->readByte();$code[]=Op::SB_LGET_LGET_I32SUB;$code[]=$localIdx;$code[]=$localIdx2;$sd++;break;} // I32_SUB
                            $code[]=Op::SB_LGET_LGET;$code[]=$localIdx;$code[]=$localIdx2;$sd+=2;break;
                        }
                        if ($nb === 0x41) { // I32_CONST follows
                            $r->readByte(); $constVal=$r->readS32();
                            if (!$r->eof() && $r->peekByte() === 0x6A) { $r->readByte(); // I32_ADD
                                if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[]=Op::SB_LGET_ICONST_IADD_LSET;$code[]=$localIdx;$code[]=$constVal;$code[]=$r->readU32();/* net 0 */break; }
                                if (!$r->eof() && $r->peekByte() === 0x22) { $r->readByte(); $teeIdx2=$r->readU32();
                                    if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brD2=$r->readU32();$csLen2=count($controlStack);
                                        // lget+iconst+iadd+tee+brif: sdAfterBr=$sd (net 0 fall-through)
                                        if($brD2>=$csLen2){$code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=Op::SB_BRIF_PRECOMP_ESC;break;}
                                        $code[]=Op::SB_LGET_ICONST_IADD_LTEE_BRIF_LOOP;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;
                                        $this->emitBranchImms($code,$controlStack,$brD2,$sd);break;
                                    }
                                    if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$ldOff2=$r->readU32();$code[]=Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$ldOff2;$sd++;break;}
                                    if(!$r->eof()&&$r->peekByte()===0x2D){$r->readByte();$r->readU32();$ldOff2=$r->readU32();$code[]=Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD8U;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$ldOff2;$sd++;break;}
                                    $code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$sd++;break;
                                }
                                if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$ldOff=$r->readU32();
                                    if(!$r->eof()&&$r->peekByte()===0x41){$r->readByte();$tagVm=$r->readS32();
                                        if(!$r->eof()&&$r->peekByte()===0x46){$r->readByte(); // I32_EQ
                                            if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDm=$r->readU32();$csLm=count($controlStack);
                                                if($brDm>=$csLm){$code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;$code[]=Op::I32_CONST;$code[]=$tagVm;$sd++;$code[]=Op::I32_EQ;$sd--;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                                $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD_I32EQ_BRIF;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$code[]=$tagVm;$this->emitBranchImms($code,$controlStack,$brDm,$sd);break;}
                                            $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;$code[]=Op::I32_CONST;$code[]=$tagVm;$sd++;$code[]=Op::I32_EQ;$sd--;break;}
                                        if(!$r->eof()&&$r->peekByte()===0x47){$r->readByte(); // I32_NE
                                            if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDm=$r->readU32();$csLm=count($controlStack);
                                                if($brDm>=$csLm){$code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;$code[]=Op::I32_CONST;$code[]=$tagVm;$sd++;$code[]=Op::I32_NE;$sd--;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                                $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD_I32NE_BRIF;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$code[]=$tagVm;$this->emitBranchImms($code,$controlStack,$brDm,$sd);break;}
                                            $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;$code[]=Op::I32_CONST;$code[]=$tagVm;$sd++;$code[]=Op::I32_NE;$sd--;break;}
                                        $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;$code[]=Op::I32_CONST;$code[]=$tagVm;$sd++;break;}
                                    $code[]=Op::SB_LGET_ICONST_IADD_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;break;}
                                if(!$r->eof()&&$r->peekByte()===0x2D){$r->readByte();$r->readU32();$ldOff=$r->readU32();$code[]=Op::SB_LGET_ICONST_IADD_I32LOAD8U;$code[]=$localIdx;$code[]=$constVal;$code[]=$ldOff;$sd++;break;}
                                $code[]=Op::SB_LGET_ICONST_IADD;$code[]=$localIdx;$code[]=$constVal;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x4A){$r->readByte(); // I32_GT_S
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDg=$r->readU32();$csLng=count($controlStack);
                                    // lget+iconst+i32.gt_s+brif: sdAfterBr=$sd (net 0)
                                    if($brDg>=$csLng){$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_GT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_ICONST_I32GTS_BRIF;$code[]=$localIdx;$code[]=$constVal;
                                    $this->emitBranchImms($code,$controlStack,$brDg,$sd);break;
                                }
                                $code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_GT_S;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x47){$r->readByte(); // I32_NE
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDne=$r->readU32();$csLne=count($controlStack);
                                    if($brDne>=$csLne){$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_NE;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_ICONST_I32NE_BRIF;$code[]=$localIdx;$code[]=$constVal;
                                    $this->emitBranchImms($code,$controlStack,$brDne,$sd);break;
                                }
                                $code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_NE;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x48){$r->readByte(); // I32_LT_S
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlt=$r->readU32();$csLlt=count($controlStack);
                                    if($brDlt>=$csLlt){$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_LT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_ICONST_I32LTS_BRIF;$code[]=$localIdx;$code[]=$constVal;
                                    $this->emitBranchImms($code,$controlStack,$brDlt,$sd);break;
                                }
                                $code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_LT_S;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x46){$r->readByte(); // I32_EQ
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDeq=$r->readU32();$csLeq=count($controlStack);
                                    if($brDeq>=$csLeq){$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_EQ;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_ICONST_I32EQ_BRIF;$code[]=$localIdx;$code[]=$constVal;
                                    $this->emitBranchImms($code,$controlStack,$brDeq,$sd);break;
                                }
                                $code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_EQ;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x71){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32AND;$code[]=$localIdx;$code[]=$constVal;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x72){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32OR;$code[]=$localIdx;$code[]=$constVal;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x74){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32SHL;$code[]=$localIdx;$code[]=$constVal;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x75){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32SHR_S;$code[]=$localIdx;$code[]=$constVal;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x76){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32SHR_U;$code[]=$localIdx;$code[]=$constVal;$sd++;break;}
                            if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_ICONST_I32STORE;$code[]=$localIdx;$code[]=$constVal;$code[]=$r->readU32();/* net 0 */break;}
                            $code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$sd+=2;break;
                        }
                        if ($nb === 0x6A) { $r->readByte(); $code[]=Op::SB_LGET_I32ADD;$code[]=$localIdx;/* net 0 */break; }
                        if ($nb === 0x29) { $r->readByte();$r->readU32();$code[]=Op::SB_LGET_I64LOAD;$code[]=$localIdx;$code[]=$r->readU32();$sd++;break; }
                        if ($nb === 0x42) { $r->readByte(); $c64g=$r->readS64();
                            if(!$r->eof()&&$r->peekByte()===0x54){$r->readByte();
                                if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlg=$r->readU32();$csLlg=count($controlStack);
                                    // lget+i64const+i64lt_u+brif: sdAfterBr=$sd (net 0)
                                    if($brDlg>=$csLlg){$code[]=Op::SB_LGET_I64CONST;$code[]=$localIdx;$code[]=$c64g;$code[]=Op::I64_LT_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                                    $code[]=Op::SB_LGET_I64CONST_I64LTU_BRIF;$code[]=$localIdx;$code[]=$c64g;
                                    $this->emitBranchImms($code,$controlStack,$brDlg,$sd);break;
                                }
                                $code[]=Op::SB_LGET_I64CONST;$code[]=$localIdx;$code[]=$c64g;$code[]=Op::I64_LT_U;$sd++;break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_LGET_I64CONST_I64AND;$code[]=$localIdx;$code[]=$c64g;$sd++;break;}
                            $code[]=Op::SB_LGET_I64CONST;$code[]=$localIdx;$code[]=$c64g;$sd+=2;break;
                        }
                        if ($nb === 0x28) { $r->readByte();$r->readU32();$offset=$r->readU32();
                            if(!$r->eof()&&$r->peekByte()===0x21){$r->readByte();$code[]=Op::SB_LGET_I32LOAD_LSET;$code[]=$localIdx;$code[]=$offset;$code[]=$r->readU32();/* net 0 */break;}
                            if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$teeIdx=$r->readU32();$code[]=Op::SB_LGET_I32LOAD_LTEE;$code[]=$localIdx;$code[]=$offset;$code[]=$teeIdx;$sd++;break;}
                            $code[]=Op::SB_LGET_I32LOAD;$code[]=$localIdx;$code[]=$offset;$sd++;break;
                        }
                        if ($nb === 0x2D) { $r->readByte();$r->readU32();$offset=$r->readU32();
                            if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$teeIdx=$r->readU32();$code[]=Op::SB_LGET_I32LOAD8U_LTEE;$code[]=$localIdx;$code[]=$offset;$code[]=$teeIdx;$sd++;break;}
                            $code[]=Op::SB_LGET_I32LOAD8U;$code[]=$localIdx;$code[]=$offset;$sd++;break;
                        }
                        if ($nb === 0xA7) { $r->readByte();
                            if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_LGET_I32WRAP_LTEE;$code[]=$localIdx;$code[]=$r->readU32();$sd++;break;}
                            $code[]=Op::SB_LGET_I32WRAP;$code[]=$localIdx;$sd++;break;
                        }
                        if ($nb === 0x21) { $r->readByte();$code[]=Op::SB_LGET_LSET;$code[]=$localIdx;$code[]=$r->readU32();/* net 0 */break; }
                        if ($nb === 0x6B) { $r->readByte();$code[]=Op::SB_LGET_I32SUB;$code[]=$localIdx;/* net 0 */break; }
                    }
                    $code[]=Op::LOCAL_GET;$code[]=$localIdx;$sd++;break;
                }
                case 0x21: $code[]=Op::LOCAL_SET;$code[]=$r->readU32();$sd--;break;
                case 0x22: { $teeIdx=$r->readU32();
                    if(!$r->eof()){$nb2=$r->peekByte();
                        if($nb2===0x41){$r->readByte();$code[]=Op::SB_LTEE_ICONST;$code[]=$teeIdx;$code[]=$r->readS32();$sd++;break;}
                        if($nb2===0x42){$r->readByte();$code[]=Op::SB_LTEE_I64CONST;$code[]=$teeIdx;$code[]=$r->readS64();$sd++;break;}
                        if($nb2===0x0D){$r->readByte();$brDt=$r->readU32();$csLt=count($controlStack);
                            // tee+brif: sdAfterBr=$sd-1 (br_if pops condition)
                            if($brDt>=$csLt){$code[]=Op::LOCAL_TEE;$code[]=$teeIdx;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                            $code[]=Op::SB_LTEE_BRIF;$code[]=$teeIdx;
                            $this->emitBranchImms($code,$controlStack,$brDt,$sd-1);
                            $sd--;break;
                        }
                    }
                    $code[]=Op::LOCAL_TEE;$code[]=$teeIdx;/* net 0 */break;
                }
                case 0x23: $code[]=Op::GLOBAL_GET;$code[]=$r->readU32();$sd++;break;
                case 0x24: $code[]=Op::GLOBAL_SET;$code[]=$r->readU32();$sd--;break;

                // ---- Table ----
                case 0x25: $code[]=Op::TABLE_GET;$code[]=$r->readU32();/* net 0: pop idx push ref */break;
                case 0x26: $code[]=Op::TABLE_SET;$code[]=$r->readU32();$sd-=2;break;

                // ---- Memory load (align ignored, read offset inline) ----
                // All loads: pop addr push value → net 0
                case 0x28: { $r->readU32(); $off=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();/* net 0 */break;} $code[]=Op::I32_LOAD;$code[]=$off;break; }
                case 0x29: { $r->readU32(); $off=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I64LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();/* net 0 */break;} $code[]=Op::I64_LOAD;$code[]=$off;break; }
                case 0x2A: $code[]=Op::F32_LOAD;     $r->readU32(); $code[]=$r->readU32(); break;
                case 0x2B: $code[]=Op::F64_LOAD;     $r->readU32(); $code[]=$r->readU32(); break;
                case 0x2C: $code[]=Op::I32_LOAD8_S;  $r->readU32(); $code[]=$r->readU32(); break;
                case 0x2D: $code[]=Op::I32_LOAD8_U;  $r->readU32(); $code[]=$r->readU32(); break;
                case 0x2E: $code[]=Op::I32_LOAD16_S; $r->readU32(); $code[]=$r->readU32(); break;
                case 0x2F: $code[]=Op::I32_LOAD16_U; $r->readU32(); $code[]=$r->readU32(); break;
                case 0x30: $code[]=Op::I64_LOAD8_S;  $r->readU32(); $code[]=$r->readU32(); break;
                case 0x31: $code[] = Op::I64_LOAD8_U;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x32: $code[] = Op::I64_LOAD16_S; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x33: $code[] = Op::I64_LOAD16_U; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x34: $code[] = Op::I64_LOAD32_S; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x35: $code[] = Op::I64_LOAD32_U; $r->readU32(); $code[] = $r->readU32(); break;

                // ---- Memory store (align ignored, read offset inline) ----
                // All stores: pop addr + value → net -2
                case 0x36: $code[]=Op::I32_STORE;   $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x37: $code[]=Op::I64_STORE;   $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x38: $code[]=Op::F32_STORE;   $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x39: $code[]=Op::F64_STORE;   $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x3A: $code[]=Op::I32_STORE8;  $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x3B: $code[]=Op::I32_STORE16; $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x3C: $code[]=Op::I64_STORE8;  $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x3D: $code[]=Op::I64_STORE16; $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;
                case 0x3E: $code[]=Op::I64_STORE32; $r->readU32(); $code[]=$r->readU32(); $sd-=2; break;

                // ---- Memory management ----
                case 0x3F: $r->readByte(); $code[]=Op::MEMORY_SIZE; $sd++; break;
                case 0x40: $r->readByte(); $code[]=Op::MEMORY_GROW; /* net 0 */ break;

                // ---- Constants ----
                case 0x41: {
                    $constVal=$r->readS32();
                    if(!$r->eof()&&$r->peekByte()===0x6A){$r->readByte();
                        if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_ICONST_IADD_I32STORE;$code[]=$constVal;$code[]=$r->readU32();$sd-=2;break;}
                        $code[]=Op::SB_ICONST_IADD;$code[]=$constVal;/* net 0 */break;
                    }
                    if(!$r->eof()&&$r->peekByte()===0x71){$r->readByte();$code[]=Op::SB_ICONST_I32AND;$code[]=$constVal;/* net 0 */break;}
                    if(!$r->eof()&&$r->peekByte()===0x21){$r->readByte();$code[]=Op::SB_ICONST_LSET;$code[]=$constVal;$code[]=$r->readU32();/* net 0 */break;}
                    if(!$r->eof()&&$r->peekByte()===0x74){$r->readByte();$code[]=Op::SB_ICONST_I32SHL;$code[]=$constVal;/* net 0 */break;}
                    $code[]=Op::I32_CONST;$code[]=$constVal;$sd++;break;
                }
                case 0x42: { $c64=$r->readS64();
                    if(!$r->eof()&&$r->peekByte()===0x21){$r->readByte();$code[]=Op::SB_I64CONST_LSET;$code[]=$c64;$code[]=$r->readU32();/* net 0 */break;}
                    if(!$r->eof()&&$r->peekByte()===0x54){$r->readByte(); // I64_LT_U follows
                        if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlt=$r->readU32();$csLlt=count($controlStack);
                            // i64const+i64lt_u+brif: sdAfterBr=$sd-1 (TOS was i64, const+ltu = net 0, brif pops → -1)
                            if($brDlt>=$csLlt){$code[]=Op::I64_CONST;$code[]=$c64;$code[]=Op::I64_LT_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                            $code[]=Op::SB_I64CONST_I64LTU_BRIF;$code[]=$c64;
                            $this->emitBranchImms($code,$controlStack,$brDlt,$sd-1);
                            $sd--;break;
                        }
                        $code[]=Op::I64_CONST;$code[]=$c64;$code[]=Op::I64_LT_U;$sd--;break;
                    }
                    if(!$r->eof()&&$r->peekByte()===0x37){$r->readByte();$r->readU32();$code[]=Op::SB_I64CONST_I64STORE;$code[]=$c64;$code[]=$r->readU32();$sd--;break;}
                    if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_I64CONST_I64AND;$code[]=$c64;/* net 0 */break;}
                    $code[]=Op::I64_CONST;$code[]=$c64;$sd++;break;
                }
                case 0x43: $code[]=Op::F32_CONST;$code[]=$r->readF32();$sd++;break;
                case 0x44: $code[]=Op::F64_CONST;$code[]=$r->readF64();$sd++;break;

                // ---- i32/i64 comparisons with BRIF peepholes ----
                // Helper macro (inlined): binary-compare + br_if → [targetIp, spDelta, rCnt]
                // sdAfterBr for unary-compare+brif = $sd-1 (net -1 fall-through)
                // sdAfterBr for binary-compare+brif = $sd-2 (net -2 fall-through)
                case 0x45: { // I32_EQZ + br_if peephole
                    if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);
                        if($brDepth>=$csLen){$code[]=Op::I32_EQZ;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd--;break;}
                        $code[]=Op::SB_I32EQZ_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-1);$sd--;break;
                    }
                    $code[]=Op::I32_EQZ;/* net 0 */break;
                }
                case 0x46: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_EQ;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}
                    $cL46=count($code);if($cL46>=2&&$code[$cL46-2]===Op::I32_CONST){$code[$cL46-2]=Op::SB_I32CONST_I32EQ_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;}
                    $code[]=Op::SB_I32EQ_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_EQ;$sd--;break; }
                case 0x47: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_NE;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}
                    $cL47=count($code);if($cL47>=2&&$code[$cL47-2]===Op::I32_CONST){$code[$cL47-2]=Op::SB_I32CONST_I32NE_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;}
                    $code[]=Op::SB_I32NE_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_NE;$sd--;break; }
                case 0x48: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_LT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32LTS_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_LT_S;$sd--;break; }
                case 0x49: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_LT_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32LTU_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_LT_U;$sd--;break; }
                case 0x4A: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_GT_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32GTS_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_GT_S;$sd--;break; }
                case 0x4B: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_GT_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32GTU_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_GT_U;$sd--;break; }
                case 0x4C: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_LE_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32LES_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_LE_S;$sd--;break; }
                case 0x4D: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_LE_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32LEU_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_LE_U;$sd--;break; }
                case 0x4E: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_GE_S;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32GES_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_GE_S;$sd--;break; }
                case 0x4F: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I32_GE_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I32GEU_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I32_GE_U;$sd--;break; }

                // ---- i64 comparison ----
                case 0x50: $code[]=Op::I64_EQZ;/* net 0 */break;
                case 0x51: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I64_EQ;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I64EQ_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I64_EQ;$sd--;break; }
                case 0x52: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I64_NE;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I64NE_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I64_NE;$sd--;break; }
                case 0x53: $code[]=Op::I64_LT_S;$sd--;break;
                case 0x54: { if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDepth=$r->readU32();$csLen=count($controlStack);if($brDepth>=$csLen){$code[]=Op::I64_LT_U;$code[]=Op::SB_BRIF_PRECOMP_ESC;$sd-=2;break;}$code[]=Op::SB_I64LTU_BRIF;$this->emitBranchImms($code,$controlStack,$brDepth,$sd-2);$sd-=2;break;} $code[]=Op::I64_LT_U;$sd--;break; }
                case 0x55: $code[]=Op::I64_GT_S;$sd--;break;
                case 0x56: $code[]=Op::I64_GT_U;$sd--;break;
                case 0x57: $code[]=Op::I64_LE_S;$sd--;break;
                case 0x58: $code[]=Op::I64_LE_U;$sd--;break;
                case 0x59: $code[]=Op::I64_GE_S;$sd--;break;
                case 0x5A: $code[]=Op::I64_GE_U;$sd--;break;

                // ---- f32/f64 comparison (binary → net -1) ----
                case 0x5B: $code[]=Op::F32_EQ;$sd--;break;
                case 0x5C: $code[]=Op::F32_NE;$sd--;break;
                case 0x5D: $code[]=Op::F32_LT;$sd--;break;
                case 0x5E: $code[]=Op::F32_GT;$sd--;break;
                case 0x5F: $code[]=Op::F32_LE;$sd--;break;
                case 0x60: $code[]=Op::F32_GE;$sd--;break;
                case 0x61: $code[]=Op::F64_EQ;$sd--;break;
                case 0x62: $code[]=Op::F64_NE;$sd--;break;
                case 0x63: $code[]=Op::F64_LT;$sd--;break;
                case 0x64: $code[]=Op::F64_GT;$sd--;break;
                case 0x65: $code[]=Op::F64_LE;$sd--;break;
                case 0x66: $code[]=Op::F64_GE;$sd--;break;

                // ---- i32 arithmetic (unary net 0, binary net -1) ----
                case 0x67: $code[]=Op::I32_CLZ;break;
                case 0x68: $code[]=Op::I32_CTZ;break;
                case 0x69: $code[]=Op::I32_POPCNT;break;
                case 0x6A: $code[]=Op::I32_ADD;$sd--;break;
                case 0x6B: { if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32SUB_LTEE;$code[]=$r->readU32();$sd--;break;} $code[]=Op::I32_SUB;$sd--;break; }
                case 0x6C: $code[]=Op::I32_MUL;$sd--;break;
                case 0x6D: $code[]=Op::I32_DIV_S;$sd--;break;
                case 0x6E: $code[]=Op::I32_DIV_U;$sd--;break;
                case 0x6F: $code[]=Op::I32_REM_S;$sd--;break;
                case 0x70: $code[]=Op::I32_REM_U;$sd--;break;
                case 0x71: $code[]=Op::I32_AND;$sd--;break;
                case 0x72: $code[]=Op::I32_OR;$sd--;break;
                case 0x73: $code[]=Op::I32_XOR;$sd--;break;
                case 0x74: $code[]=Op::I32_SHL;$sd--;break;
                case 0x75: $code[]=Op::I32_SHR_S;$sd--;break;
                case 0x76: $code[]=Op::I32_SHR_U;$sd--;break;
                case 0x77: $code[]=Op::I32_ROTL;$sd--;break;
                case 0x78: $code[]=Op::I32_ROTR;$sd--;break;

                // ---- i64 arithmetic ----
                case 0x79: $code[]=Op::I64_CLZ;break;  // unary
                case 0x7A: $code[]=Op::I64_CTZ;break;
                case 0x7B: $code[]=Op::I64_POPCNT;break;
                case 0x7C: $code[]=Op::I64_ADD;$sd--;break;
                case 0x7D: $code[]=Op::I64_SUB;$sd--;break;
                case 0x7E: $code[]=Op::I64_MUL;$sd--;break;
                case 0x7F: $code[]=Op::I64_DIV_S;$sd--;break;
                case 0x80: $code[]=Op::I64_DIV_U;$sd--;break;
                case 0x81: $code[]=Op::I64_REM_S;$sd--;break;
                case 0x82: $code[]=Op::I64_REM_U;$sd--;break;
                case 0x83: $code[]=Op::I64_AND;$sd--;break;
                case 0x84: $code[]=Op::I64_OR;$sd--;break;
                case 0x85: $code[]=Op::I64_XOR;$sd--;break;
                case 0x86: $code[]=Op::I64_SHL;$sd--;break;
                case 0x87: $code[]=Op::I64_SHR_S;$sd--;break;
                case 0x88: $code[]=Op::I64_SHR_U;$sd--;break;
                case 0x89: $code[]=Op::I64_ROTL;$sd--;break;
                case 0x8A: $code[]=Op::I64_ROTR;$sd--;break;

                // ---- f32/f64 arithmetic (unary net 0, binary net -1) ----
                case 0x8B: $code[]=Op::F32_ABS;break;
                case 0x8C: $code[]=Op::F32_NEG;break;
                case 0x8D: $code[]=Op::F32_CEIL;break;
                case 0x8E: $code[]=Op::F32_FLOOR;break;
                case 0x8F: $code[]=Op::F32_TRUNC;break;
                case 0x90: $code[]=Op::F32_NEAREST;break;
                case 0x91: $code[]=Op::F32_SQRT;break;
                case 0x92: $code[]=Op::F32_ADD;$sd--;break;
                case 0x93: $code[]=Op::F32_SUB;$sd--;break;
                case 0x94: $code[]=Op::F32_MUL;$sd--;break;
                case 0x95: $code[]=Op::F32_DIV;$sd--;break;
                case 0x96: $code[]=Op::F32_MIN;$sd--;break;
                case 0x97: $code[]=Op::F32_MAX;$sd--;break;
                case 0x98: $code[]=Op::F32_COPYSIGN;$sd--;break;
                case 0x99: $code[]=Op::F64_ABS;break;
                case 0x9A: $code[]=Op::F64_NEG;break;
                case 0x9B: $code[]=Op::F64_CEIL;break;
                case 0x9C: $code[]=Op::F64_FLOOR;break;
                case 0x9D: $code[]=Op::F64_TRUNC;break;
                case 0x9E: $code[]=Op::F64_NEAREST;break;
                case 0x9F: $code[]=Op::F64_SQRT;break;
                case 0xA0: $code[]=Op::F64_ADD;$sd--;break;
                case 0xA1: $code[]=Op::F64_SUB;$sd--;break;
                case 0xA2: $code[]=Op::F64_MUL;$sd--;break;
                case 0xA3: $code[]=Op::F64_DIV;$sd--;break;
                case 0xA4: $code[]=Op::F64_MIN;$sd--;break;
                case 0xA5: $code[]=Op::F64_MAX;$sd--;break;
                case 0xA6: $code[]=Op::F64_COPYSIGN;$sd--;break;

                // ---- Conversions (all 1→1, net 0) ----
                case 0xA7: $code[]=Op::I32_WRAP_I64;break;
                case 0xA8: $code[]=Op::I32_TRUNC_F32_S;break;
                case 0xA9: $code[]=Op::I32_TRUNC_F32_U;break;
                case 0xAA: $code[]=Op::I32_TRUNC_F64_S;break;
                case 0xAB: $code[]=Op::I32_TRUNC_F64_U;break;
                case 0xAC: $code[]=Op::I64_EXTEND_I32_S;break;
                case 0xAD: $code[]=Op::I64_EXTEND_I32_U;break;
                case 0xAE: $code[]=Op::I64_TRUNC_F32_S;break;
                case 0xAF: $code[]=Op::I64_TRUNC_F32_U;break;
                case 0xB0: $code[]=Op::I64_TRUNC_F64_S;break;
                case 0xB1: $code[]=Op::I64_TRUNC_F64_U;break;
                case 0xB2: $code[]=Op::F32_CONVERT_I32_S;break;
                case 0xB3: $code[]=Op::F32_CONVERT_I32_U;break;
                case 0xB4: $code[]=Op::F32_CONVERT_I64_S;break;
                case 0xB5: $code[]=Op::F32_CONVERT_I64_U;break;
                case 0xB6: $code[]=Op::F32_DEMOTE_F64;break;
                case 0xB7: $code[]=Op::F64_CONVERT_I32_S;break;
                case 0xB8: $code[]=Op::F64_CONVERT_I32_U;break;
                case 0xB9: $code[]=Op::F64_CONVERT_I64_S;break;
                case 0xBA: $code[]=Op::F64_CONVERT_I64_U;break;
                case 0xBB: $code[]=Op::F64_PROMOTE_F32;break;
                case 0xBC: $code[]=Op::I32_REINTERPRET_F32;break;
                case 0xBD: $code[]=Op::I64_REINTERPRET_F64;break;
                case 0xBE: $code[]=Op::F32_REINTERPRET_I32;break;
                case 0xBF: $code[]=Op::F64_REINTERPRET_I64;break;
                case 0xC0: $code[]=Op::I32_EXTEND8_S;break;
                case 0xC1: $code[]=Op::I32_EXTEND16_S;break;
                case 0xC2: $code[]=Op::I64_EXTEND8_S;break;
                case 0xC3: $code[]=Op::I64_EXTEND16_S;break;
                case 0xC4: $code[]=Op::I64_EXTEND32_S;break;

                // ---- References ----
                case 0xD0: $this->readHeapType($r);$code[]=Op::REF_NULL;$sd++;break;
                case 0xD1: $code[]=Op::REF_IS_NULL;break; // net 0
                case 0xD2: $code[]=Op::REF_FUNC;$code[]=$r->readU32();$sd++;break;

                // ---- Multi-byte prefix (0xFC) ----
                case 0xFC: $this->decodeFCPrefixed($r, $code, $sd); break;

                default:
                    throw new WasmError("unknown opcode: 0x" . dechex($opcode));
            }
        }

        return $code;
    }

    private function fixupIf(array &$code, array $frame, int $endIp): void
    {
        $elseIp = $frame['elseIp'] ?? null;
        if ($elseIp !== null) {
            // if with else: falseTargetIp was already patched to $elseIp+2 when 0x05 was seen.
            // Now fix up ELSE_ [endIp] slot.
            $code[$elseIp + 1] = $endIp;
        } else {
            // if without else: falseTargetIp = $endIp (jump past body when condition false)
            $code[$frame['falseSlot']] = $endIp;
        }
    }

    /**
     * Decode block type from binary format.
     * Returns ?FuncType matching what the Executor expects.
     */
    private function decodeBlockType(BinaryReader $r): ?FuncType
    {
        $byte = $r->peekByte();

        if ($byte === 0x40) {
            $r->readByte();
            return null; // void → void
        }

        // Single value type result (i32=0x7F, i64=0x7E, f32=0x7D, f64=0x7C, funcref=0x70, externref=0x6F)
        if ($byte >= 0x6F && $byte <= 0x7F) {
            $r->readByte();
            return new FuncType([], [$byte]);
        }

        // GC proposal: reference type constructors as single-value block types
        // (ref null ht) = 0x63, (ref ht) = 0x64, and other GC heap types 0x65-0x6E
        if ($byte >= 0x63 && $byte <= 0x6E) {
            // Read the full reference type but map to funcref/externref for our purposes
            $refType = $this->readRefType($r);
            return new FuncType([], [$refType]);
        }

        // Type index (s33) — positive indices reference the type section
        $idx = $r->readS33();
        if ($idx < 0 || $idx >= count($this->types)) {
            throw new WasmError("invalid block type index: $idx");
        }
        return $this->types[$idx];
    }

    /**
     * Decode 0xFC-prefixed opcodes into flat bytecode.
     * Updates $sd (static stack depth) for each operation.
     */
    private function decodeFCPrefixed(BinaryReader $r, array &$code, int &$sd): void
    {
        $sub = $r->readU32();
        match ($sub) {
            // Saturating truncation — all 1→1, net 0
            0  => $code[] = Op::I32_TRUNC_SAT_F32_S,
            1  => $code[] = Op::I32_TRUNC_SAT_F32_U,
            2  => $code[] = Op::I32_TRUNC_SAT_F64_S,
            3  => $code[] = Op::I32_TRUNC_SAT_F64_U,
            4  => $code[] = Op::I64_TRUNC_SAT_F32_S,
            5  => $code[] = Op::I64_TRUNC_SAT_F32_U,
            6  => $code[] = Op::I64_TRUNC_SAT_F64_S,
            7  => $code[] = Op::I64_TRUNC_SAT_F64_U,

            // Bulk memory operations
            // memory.init: pop dst, src, len → net -3
            8  => (function() use ($r, &$code, &$sd) { $this->decodeMemoryInit($r, $code); $sd -= 3; })(),
            // data.drop: no stack effect
            9  => $this->decodeDataDrop($r, $code),
            // memory.copy: pop dst, src, len → net -3
            10 => (function() use ($r, &$code, &$sd) { $this->decodeMemoryCopy($r, $code); $sd -= 3; })(),
            // memory.fill: pop dst, val, len → net -3
            11 => (function() use ($r, &$code, &$sd) { $this->decodeMemoryFill($r, $code); $sd -= 3; })(),

            // Table operations
            // table.init: pop dst, src, len → net -3
            12 => (function() use ($r, &$code, &$sd) { $this->decodeTableInit($r, $code); $sd -= 3; })(),
            // elem.drop: no stack effect
            13 => $this->decodeElemDrop($r, $code),
            // table.copy: pop dst, src, len → net -3
            14 => (function() use ($r, &$code, &$sd) { $this->decodeTableCopy($r, $code); $sd -= 3; })(),
            // table.grow: pop initVal, n → push result → net -1
            15 => (function() use ($r, &$code, &$sd) { $this->decodeTableGrow($r, $code); $sd--; })(),
            // table.size: push size → net +1
            16 => (function() use ($r, &$code, &$sd) { $this->decodeTableSize($r, $code); $sd++; })(),
            // table.fill: pop table, val, len → net -3
            17 => (function() use ($r, &$code, &$sd) { $this->decodeTableFill($r, $code); $sd -= 3; })(),

            default => throw new WasmError("unknown 0xFC sub-opcode: $sub"),
        };
    }

    private function decodeMemoryInit(BinaryReader $r, array &$code): void
    {
        $segIdx = $r->readU32();
        $r->readByte(); // must be 0x00
        $code[] = Op::MEMORY_INIT; $code[] = $segIdx;
    }

    private function decodeDataDrop(BinaryReader $r, array &$code): void
    {
        $code[] = Op::DATA_DROP; $code[] = $r->readU32();
    }

    private function decodeMemoryCopy(BinaryReader $r, array &$code): void
    {
        $r->readByte(); $r->readByte();
        $code[] = Op::MEMORY_COPY;
    }

    private function decodeMemoryFill(BinaryReader $r, array &$code): void
    {
        $r->readByte();
        $code[] = Op::MEMORY_FILL;
    }

    private function decodeTableInit(BinaryReader $r, array &$code): void
    {
        $elemIdx  = $r->readU32();
        $tableIdx = $r->readU32();
        $code[] = Op::TABLE_INIT; $code[] = $tableIdx; $code[] = $elemIdx;
    }

    private function decodeElemDrop(BinaryReader $r, array &$code): void
    {
        $code[] = Op::ELEM_DROP; $code[] = $r->readU32();
    }

    private function decodeTableCopy(BinaryReader $r, array &$code): void
    {
        $dstTable = $r->readU32();
        $srcTable = $r->readU32();
        $code[] = Op::TABLE_COPY; $code[] = $dstTable; $code[] = $srcTable;
    }

    private function decodeTableGrow(BinaryReader $r, array &$code): void
    {
        $code[] = Op::TABLE_GROW; $code[] = $r->readU32();
    }

    private function decodeTableSize(BinaryReader $r, array &$code): void
    {
        $code[] = Op::TABLE_SIZE; $code[] = $r->readU32();
    }

    private function decodeTableFill(BinaryReader $r, array &$code): void
    {
        $code[] = Op::TABLE_FILL; $code[] = $r->readU32();
    }

    /**
     * Read memory argument (align + offset), return only offset.
     */
    private function readMemArg(BinaryReader $r): int
    {
        $r->readU32(); // align (ignored)
        return $r->readU32(); // offset
    }
}
