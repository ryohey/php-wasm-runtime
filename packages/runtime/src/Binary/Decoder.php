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

    private function decodeCodeSection(BinaryReader $r): void
    {
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
     * Decode expression into flat bytecode stream.
     *
     * Format: Op::XXX, imm1, imm2, ... (flat array, no sub-arrays per instruction)
     *
     * Control flow layout in the flat stream:
     *   BLOCK: Op::BLOCK, paramCount, resultCount, endIp
     *   LOOP:  Op::LOOP,  paramCount, contIp
     *   IF:    Op::IF_,   paramCount, resultCount, elseIp, endIp
     *   ELSE:  Op::ELSE_, endIp
     *   END:   Op::END
     */
    private function decodeExpr(BinaryReader $r): array
    {
        $code = [];

        // Control stack: each entry is [kind, ipOfOpcode, elseIp|null]
        // ipOfOpcode points to the Op::BLOCK/LOOP/IF_ slot in $code
        $controlStack = [];

        while (!$r->eof()) {
            $opcode = $r->readByte();

            if ($opcode === 0x0B) {
                // end
                if (empty($controlStack)) {
                    $code[] = Op::END;
                    break;
                }
                $frame = array_pop($controlStack);
                $endIp = count($code);

                match ($frame[0]) {
                    'block' => $code[$frame[1] + 3] = $endIp,          // block: fixup endIp (at +3: BLOCK,pc,rc,endIp)
                    'loop'  => null,                                    // loop: no endIp slot needed
                    'if'    => $this->fixupIf($code, $frame, $endIp),
                };

                $code[] = Op::END;
                continue;
            }

            if ($opcode === 0x05) {
                // else
                if (empty($controlStack)) {
                    throw new WasmError('else without matching if');
                }
                $elseIp = count($code);
                $controlStack[count($controlStack) - 1][2] = $elseIp;
                $code[] = Op::ELSE_;
                $code[] = -1; // endIp placeholder
                continue;
            }

            // ---- Inline decodeInstruction ----
            switch ($opcode) {
                // ---- Control flow ----
                case 0x00: $code[] = Op::UNREACHABLE; break;
                case 0x01: break; // NOP — skip, no-op needs no dispatch slot

                case 0x02: // block
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; $btr = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; $btr = 1; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; $btr = $bt ? count($bt->results) : 0; } }
                    $ip = count($code);
                    $code[] = Op::BLOCK; $code[] = $btp; $code[] = $btr; $code[] = -1;
                    $controlStack[] = ['block', $ip, null];
                    break;

                case 0x03: // loop
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; } }
                    $ip = count($code);
                    $code[] = Op::LOOP;
                    $code[] = $btp;  // paramCount (also = result arity for BR-to-loop)
                    $code[] = $ip + 3;      // contIp = first body instruction
                    $controlStack[] = ['loop', $ip, null];
                    break;

                case 0x04: // if
                    { $btb = $r->peekByte(); if ($btb === 0x40) { $r->readByte(); $btp = 0; $btr = 0; }
                      elseif ($btb >= 0x6F && $btb <= 0x7F) { $r->readByte(); $btp = 0; $btr = 1; }
                      else { $bt = $this->decodeBlockType($r); $btp = $bt ? count($bt->params) : 0; $btr = $bt ? count($bt->results) : 0; } }
                    $ip = count($code);
                    $code[] = Op::IF_; $code[] = $btp; $code[] = $btr;
                    $code[] = -1; // elseIp placeholder
                    $code[] = -1; // endIp placeholder
                    $controlStack[] = ['if', $ip, null];
                    break;

                // ---- Branch ----
                case 0x0C: { // BR — emit fast loop-continue variant when possible
                    $brDepth = $r->readU32();
                    if ($brDepth === 0) {
                        $csLen = count($controlStack);
                        if ($csLen > 0) { $topF = $controlStack[$csLen - 1]; if ($topF[0] === 'loop' && $code[$topF[1] + 1] === 0) { $code[] = Op::SB_BR_LOOP; $code[] = $code[$topF[1] + 2]; break; } }
                    }
                    $code[] = Op::BR; $code[] = $brDepth; break;
                }
                case 0x0D: { // BR_IF — emit fast loop-continue variant when possible
                    $brDepth = $r->readU32();
                    $csLen = count($controlStack);
                    if ($brDepth === 0 && $csLen > 0) {
                        $topF = $controlStack[$csLen - 1];
                        if ($topF[0] === 'loop' && $code[$topF[1] + 1] === 0) {
                            $code[] = Op::SB_BRIF_LOOP; $code[] = $code[$topF[1] + 2]; break;
                        }
                    }
                    $code[] = Op::BR_IF; $code[] = $brDepth; break;
                }

                case 0x0E: { // br_table
                    $labels = $r->readVec(fn() => $r->readU32());
                    $default = $r->readU32();
                    // Specialize to SB_BR_TABLE_VOID if all targets are 0-result blocks
                    $_csLen = count($controlStack); $_allVoid = true;
                    foreach ($labels as $_d) { $_ti=$_csLen-1-$_d; if($_ti<0||$controlStack[$_ti][0]!=='block'||$code[$controlStack[$_ti][1]+2]!==0){$_allVoid=false;break;} }
                    if ($_allVoid) { $_ti=$_csLen-1-$default; if($_ti<0||$controlStack[$_ti][0]!=='block'||$code[$controlStack[$_ti][1]+2]!==0){$_allVoid=false;} }
                    $code[] = $_allVoid ? Op::SB_BR_TABLE_VOID : Op::BR_TABLE;
                    $code[] = count($labels); // label count
                    foreach ($labels as $l) $code[] = $l;
                    $code[] = $default;
                    break;
                }

                case 0x0F: $code[] = Op::RETURN_; break;

                // ---- Calls ----
                case 0x10: $code[] = Op::CALL; $code[] = $r->readU32(); break;

                case 0x11: // call_indirect
                    $typeIdx  = $r->readU32();
                    $tableIdx = $r->readU32();
                    $code[] = Op::CALL_INDIRECT; $code[] = $typeIdx; $code[] = $tableIdx;
                    break;

                case 0x12: $code[] = Op::RETURN_CALL; $code[] = $r->readU32(); break;

                case 0x13: // return_call_indirect
                    $typeIdx  = $r->readU32();
                    $tableIdx = $r->readU32();
                    $code[] = Op::RETURN_CALL_INDIRECT; $code[] = $typeIdx; $code[] = $tableIdx;
                    break;

                // ---- Stack ----
                case 0x1A: $code[] = Op::DROP; break;
                case 0x1B: $code[] = Op::SELECT; break;
                case 0x1C: // select (typed)
                    $r->readVec(fn() => $this->readValType($r));
                    $code[] = Op::SELECT;
                    break;

                // ---- Variables ----
                case 0x20: { // LOCAL_GET — peephole for common successors
                    $localIdx = $r->readU32();
                    if (!$r->eof()) {
                        $nb = $r->peekByte();
                        if ($nb === 0x20) { // LOCAL_GET follows
                            $r->readByte();
                            $localIdx2=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32STORE;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32LOAD;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x6A){$r->readByte();$code[]=Op::SB_LGET_LGET_I32ADD;$code[]=$localIdx;$code[]=$localIdx2;break;}
                            $code[] = Op::SB_LGET_LGET; $code[] = $localIdx; $code[] = $localIdx2;
                            break;
                        }
                        if ($nb === 0x41) { // I32_CONST follows → check for I32_ADD triple
                            $r->readByte();
                            $constVal = $r->readS32();
                            if (!$r->eof() && $r->peekByte() === 0x6A) { // I32_ADD
                                $r->readByte();
                                if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_ICONST_IADD_LSET; $code[] = $localIdx; $code[] = $constVal; $code[] = $r->readU32(); break; }
                                if (!$r->eof() && $r->peekByte() === 0x22) { $r->readByte(); $teeIdx2=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brD2=$r->readU32();$csLen2=count($controlStack);if($brD2===0&&$csLen2>0){$topF2=$controlStack[$csLen2-1];if($topF2[0]==='loop'&&$code[$topF2[1]+1]===0){$code[]=Op::SB_LGET_ICONST_IADD_LTEE_BRIF_LOOP;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$code[$topF2[1]+2];break;}}$code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=Op::BR_IF;$code[]=$brD2;break;}if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$ldOff2=$r->readU32();$code[]=Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$ldOff2;break;}$code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;break; }
                                $code[] = Op::SB_LGET_ICONST_IADD; $code[] = $localIdx; $code[] = $constVal;
                                break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x4A){$r->readByte();if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDg=$r->readU32();$csLng=count($controlStack);if($brDg===0&&$csLng>0&&$controlStack[$csLng-1][0]==='loop'&&$code[$controlStack[$csLng-1][1]+1]===0){$code[]=Op::SB_LGET_ICONST_I32GTS_BRIF_LOOP;$code[]=$localIdx;$code[]=$constVal;$code[]=$code[$controlStack[$csLng-1][1]+2];break;}$code[]=Op::SB_LGET_ICONST_I32GTS_BRIF;$code[]=$localIdx;$code[]=$constVal;$code[]=$brDg;break;}$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_GT_S;break;}
                            if(!$r->eof()&&$r->peekByte()===0x71){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32AND;$code[]=$localIdx;$code[]=$constVal;break;}
                            if(!$r->eof()&&$r->peekByte()===0x74){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32SHL;$code[]=$localIdx;$code[]=$constVal;break;}
                            if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_ICONST_I32STORE;$code[]=$localIdx;$code[]=$constVal;$code[]=$r->readU32();break;}
                            $code[] = Op::SB_LGET_ICONST; $code[] = $localIdx; $code[] = $constVal;
                            break;
                        }
                        if ($nb === 0x6A) { $r->readByte(); $code[] = Op::SB_LGET_I32ADD; $code[] = $localIdx; break; } // I32_ADD follows
                        if ($nb === 0x29) { // I64_LOAD follows
                            $r->readByte(); $r->readU32(); $code[] = Op::SB_LGET_I64LOAD; $code[] = $localIdx; $code[] = $r->readU32(); break;
                        }
                        if ($nb === 0x42) { // I64_CONST follows — fuse with i64.lt_u + br_if
                            $r->readByte(); $c64g=$r->readS64();
                            if(!$r->eof()&&$r->peekByte()===0x54){$r->readByte();if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlg=$r->readU32();$csLlg=count($controlStack);if($brDlg===0&&$csLlg>0&&$controlStack[$csLlg-1][0]==='loop'&&$code[$controlStack[$csLlg-1][1]+1]===0){$code[]=Op::SB_LGET_I64CONST_I64LTU_BRIF_LOOP;$code[]=$localIdx;$code[]=$c64g;$code[]=$code[$controlStack[$csLlg-1][1]+2];break;}$code[]=Op::SB_LGET_I64CONST_I64LTU_BRIF;$code[]=$localIdx;$code[]=$c64g;$code[]=$brDlg;break;}$code[]=Op::LOCAL_GET;$code[]=$localIdx;$code[]=Op::I64_CONST;$code[]=$c64g;$code[]=Op::I64_LT_U;break;}
                            if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_LGET_I64CONST_I64AND;$code[]=$localIdx;$code[]=$c64g;break;}
                            $code[]=Op::SB_LGET_I64CONST;$code[]=$localIdx;$code[]=$c64g;break;
                        }
                        if ($nb === 0x28) { // I32_LOAD follows
                            $r->readByte();
                            $r->readU32(); // skip alignment
                            $offset = $r->readU32();
                            if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_I32LOAD_LSET; $code[] = $localIdx; $code[] = $offset; $code[] = $r->readU32(); break; }
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $teeIdx = $r->readU32();
                                $code[] = Op::SB_LGET_I32LOAD_LTEE; $code[] = $localIdx; $code[] = $offset; $code[] = $teeIdx;
                                break;
                            }
                            $code[] = Op::SB_LGET_I32LOAD; $code[] = $localIdx; $code[] = $offset;
                            break;
                        }
                        if ($nb === 0x2D) { // I32_LOAD8_U follows
                            $r->readByte();
                            $r->readU32(); // skip alignment
                            $offset = $r->readU32();
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $teeIdx = $r->readU32();
                                $code[] = Op::SB_LGET_I32LOAD8U_LTEE; $code[] = $localIdx; $code[] = $offset; $code[] = $teeIdx;
                                break;
                            }
                            $code[] = Op::SB_LGET_I32LOAD8U; $code[] = $localIdx; $code[] = $offset;
                            break;
                        }
                        if ($nb === 0xA7) { // I32_WRAP_I64 follows → check for LOCAL_TEE triple
                            $r->readByte();
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $code[] = Op::SB_LGET_I32WRAP_LTEE; $code[] = $localIdx; $code[] = $r->readU32();
                                break;
                            }
                            $code[] = Op::SB_LGET_I32WRAP; $code[] = $localIdx;
                            break;
                        }
                        if ($nb === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_LSET; $code[] = $localIdx; $code[] = $r->readU32(); break; }
                        if ($nb === 0x6B) { $r->readByte(); $code[] = Op::SB_LGET_I32SUB; $code[] = $localIdx; break; } // I32_SUB follows
                    }
                    $code[] = Op::LOCAL_GET; $code[] = $localIdx;
                    break;
                }
                case 0x21: $code[] = Op::LOCAL_SET;  $code[] = $r->readU32(); break;
                case 0x22: { $teeIdx=$r->readU32(); if(!$r->eof()){$nb2=$r->peekByte();if($nb2===0x41){$r->readByte();$code[]=Op::SB_LTEE_ICONST;$code[]=$teeIdx;$code[]=$r->readS32();break;}if($nb2===0x42){$r->readByte();$code[]=Op::SB_LTEE_I64CONST;$code[]=$teeIdx;$code[]=$r->readS64();break;}if($nb2===0x0D){$r->readByte();$code[]=Op::SB_LTEE_BRIF;$code[]=$teeIdx;$code[]=$r->readU32();break;}} $code[]=Op::LOCAL_TEE;$code[]=$teeIdx;break; }
                case 0x23: $code[] = Op::GLOBAL_GET; $code[] = $r->readU32(); break;
                case 0x24: $code[] = Op::GLOBAL_SET; $code[] = $r->readU32(); break;

                // ---- Table ----
                case 0x25: $code[] = Op::TABLE_GET; $code[] = $r->readU32(); break;
                case 0x26: $code[] = Op::TABLE_SET; $code[] = $r->readU32(); break;

                // ---- Memory load (align ignored, read offset inline) ----
                case 0x28: { $r->readU32(); $off=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();break;} $code[]=Op::I32_LOAD;$code[]=$off;break; }
                case 0x29: { $r->readU32(); $off=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I64LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();break;} $code[]=Op::I64_LOAD;$code[]=$off;break; }
                case 0x2A: $code[] = Op::F32_LOAD;     $r->readU32(); $code[] = $r->readU32(); break;
                case 0x2B: $code[] = Op::F64_LOAD;     $r->readU32(); $code[] = $r->readU32(); break;
                case 0x2C: $code[] = Op::I32_LOAD8_S;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x2D: $code[] = Op::I32_LOAD8_U;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x2E: $code[] = Op::I32_LOAD16_S; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x2F: $code[] = Op::I32_LOAD16_U; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x30: $code[] = Op::I64_LOAD8_S;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x31: $code[] = Op::I64_LOAD8_U;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x32: $code[] = Op::I64_LOAD16_S; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x33: $code[] = Op::I64_LOAD16_U; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x34: $code[] = Op::I64_LOAD32_S; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x35: $code[] = Op::I64_LOAD32_U; $r->readU32(); $code[] = $r->readU32(); break;

                // ---- Memory store (align ignored, read offset inline) ----
                case 0x36: $code[] = Op::I32_STORE;   $r->readU32(); $code[] = $r->readU32(); break;
                case 0x37: $code[] = Op::I64_STORE;   $r->readU32(); $code[] = $r->readU32(); break;
                case 0x38: $code[] = Op::F32_STORE;   $r->readU32(); $code[] = $r->readU32(); break;
                case 0x39: $code[] = Op::F64_STORE;   $r->readU32(); $code[] = $r->readU32(); break;
                case 0x3A: $code[] = Op::I32_STORE8;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x3B: $code[] = Op::I32_STORE16; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x3C: $code[] = Op::I64_STORE8;  $r->readU32(); $code[] = $r->readU32(); break;
                case 0x3D: $code[] = Op::I64_STORE16; $r->readU32(); $code[] = $r->readU32(); break;
                case 0x3E: $code[] = Op::I64_STORE32; $r->readU32(); $code[] = $r->readU32(); break;

                // ---- Memory management ----
                case 0x3F: $r->readByte(); $code[] = Op::MEMORY_SIZE; break;
                case 0x40: $r->readByte(); $code[] = Op::MEMORY_GROW; break;

                // ---- Constants ----
                case 0x41: {
                    $constVal = $r->readS32();
                    if (!$r->eof() && $r->peekByte() === 0x6A) { // I32_ADD follows
                        $r->readByte();
                        if (!$r->eof() && $r->peekByte() === 0x36) { $r->readByte(); $r->readU32(); $code[] = Op::SB_ICONST_IADD_I32STORE; $code[] = $constVal; $code[] = $r->readU32(); break; }
                        $code[] = Op::SB_ICONST_IADD; $code[] = $constVal;
                        break;
                    }
                    if (!$r->eof() && $r->peekByte() === 0x71) { $r->readByte(); $code[] = Op::SB_ICONST_I32AND; $code[] = $constVal; break; }
                    if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[] = Op::SB_ICONST_LSET; $code[] = $constVal; $code[] = $r->readU32(); break; }
                    if (!$r->eof() && $r->peekByte() === 0x74) { $r->readByte(); $code[] = Op::SB_ICONST_I32SHL; $code[] = $constVal; break; }
                    $code[] = Op::I32_CONST; $code[] = $constVal; break;
                }
                case 0x42: { $c64=$r->readS64(); if(!$r->eof()&&$r->peekByte()===0x21){$r->readByte();$code[]=Op::SB_I64CONST_LSET;$code[]=$c64;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x54){$r->readByte();if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlt=$r->readU32();$csLlt=count($controlStack);if($brDlt===0&&$csLlt>0&&$controlStack[$csLlt-1][0]==='loop'&&$code[$controlStack[$csLlt-1][1]+1]===0){$code[]=Op::SB_I64CONST_I64LTU_BRIF_LOOP;$code[]=$c64;$code[]=$code[$controlStack[$csLlt-1][1]+2];break;}$code[]=Op::SB_I64CONST_I64LTU_BRIF;$code[]=$c64;$code[]=$brDlt;break;}$code[]=Op::I64_CONST;$code[]=$c64;$code[]=Op::I64_LT_U;break;} if(!$r->eof()&&$r->peekByte()===0x37){$r->readByte();$r->readU32();$code[]=Op::SB_I64CONST_I64STORE;$code[]=$c64;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_I64CONST_I64AND;$code[]=$c64;break;} $code[]=Op::I64_CONST;$code[]=$c64;break; }
                case 0x43: $code[] = Op::F32_CONST; $code[] = $r->readF32(); break;
                case 0x44: $code[] = Op::F64_CONST; $code[] = $r->readF64(); break;

                // ---- i32 comparison ----
                case 0x45: // I32_EQZ — peephole for I32_EQZ + BR_IF
                    if (!$r->eof() && $r->peekByte() === 0x0D) {
                        $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack);
                        if ($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0) { $code[]=Op::SB_I32EQZ_BRIF_LOOP; $code[]=$code[$controlStack[$csLen-1][1]+2]; break; }
                        $code[] = Op::SB_I32EQZ_BRIF; $code[] = $brDepth; break;
                    }
                    $code[] = Op::I32_EQZ;
                    break;
                case 0x46:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32EQ_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32EQ_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_EQ; break;
                case 0x47:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32NE_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32NE_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_NE; break;
                case 0x48:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LTS_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LTS_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_LT_S; break;
                case 0x49:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LTU_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_LT_U; break;
                case 0x4A:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GTS_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GTS_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_GT_S; break;
                case 0x4B:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GTU_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_GT_U; break;
                case 0x4C:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LES_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LES_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_LE_S; break;
                case 0x4D:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LEU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LEU_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_LE_U; break;
                case 0x4E:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GES_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GES_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_GE_S; break;
                case 0x4F:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GEU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GEU_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I32_GE_U; break;

                // ---- i64 comparison ----
                case 0x50: $code[] = Op::I64_EQZ; break;
                case 0x51:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64EQ_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64EQ_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I64_EQ; break;
                case 0x52:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64NE_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64NE_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I64_NE; break;
                case 0x53: $code[] = Op::I64_LT_S; break;
                case 0x54:
                    if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64LTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64LTU_BRIF;$code[]=$brDepth;break; }
                    $code[] = Op::I64_LT_U; break;
                case 0x55: $code[] = Op::I64_GT_S; break;
                case 0x56: $code[] = Op::I64_GT_U; break;
                case 0x57: $code[] = Op::I64_LE_S; break;
                case 0x58: $code[] = Op::I64_LE_U; break;
                case 0x59: $code[] = Op::I64_GE_S; break;
                case 0x5A: $code[] = Op::I64_GE_U; break;

                // ---- f32 comparison ----
                case 0x5B: $code[] = Op::F32_EQ; break;
                case 0x5C: $code[] = Op::F32_NE; break;
                case 0x5D: $code[] = Op::F32_LT; break;
                case 0x5E: $code[] = Op::F32_GT; break;
                case 0x5F: $code[] = Op::F32_LE; break;
                case 0x60: $code[] = Op::F32_GE; break;

                // ---- f64 comparison ----
                case 0x61: $code[] = Op::F64_EQ; break;
                case 0x62: $code[] = Op::F64_NE; break;
                case 0x63: $code[] = Op::F64_LT; break;
                case 0x64: $code[] = Op::F64_GT; break;
                case 0x65: $code[] = Op::F64_LE; break;
                case 0x66: $code[] = Op::F64_GE; break;

                // ---- i32 arithmetic ----
                case 0x67: $code[] = Op::I32_CLZ; break;
                case 0x68: $code[] = Op::I32_CTZ; break;
                case 0x69: $code[] = Op::I32_POPCNT; break;
                case 0x6A: $code[] = Op::I32_ADD; break;
                case 0x6B: { if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32SUB_LTEE;$code[]=$r->readU32();break;} $code[]=Op::I32_SUB;break; }
                case 0x6C: $code[] = Op::I32_MUL; break;
                case 0x6D: $code[] = Op::I32_DIV_S; break;
                case 0x6E: $code[] = Op::I32_DIV_U; break;
                case 0x6F: $code[] = Op::I32_REM_S; break;
                case 0x70: $code[] = Op::I32_REM_U; break;
                case 0x71: $code[] = Op::I32_AND; break;
                case 0x72: $code[] = Op::I32_OR; break;
                case 0x73: $code[] = Op::I32_XOR; break;
                case 0x74: $code[] = Op::I32_SHL; break;
                case 0x75: $code[] = Op::I32_SHR_S; break;
                case 0x76: $code[] = Op::I32_SHR_U; break;
                case 0x77: $code[] = Op::I32_ROTL; break;
                case 0x78: $code[] = Op::I32_ROTR; break;

                // ---- i64 arithmetic ----
                case 0x79: $code[] = Op::I64_CLZ; break;
                case 0x7A: $code[] = Op::I64_CTZ; break;
                case 0x7B: $code[] = Op::I64_POPCNT; break;
                case 0x7C: $code[] = Op::I64_ADD; break;
                case 0x7D: $code[] = Op::I64_SUB; break;
                case 0x7E: $code[] = Op::I64_MUL; break;
                case 0x7F: $code[] = Op::I64_DIV_S; break;
                case 0x80: $code[] = Op::I64_DIV_U; break;
                case 0x81: $code[] = Op::I64_REM_S; break;
                case 0x82: $code[] = Op::I64_REM_U; break;
                case 0x83: $code[] = Op::I64_AND; break;
                case 0x84: $code[] = Op::I64_OR; break;
                case 0x85: $code[] = Op::I64_XOR; break;
                case 0x86: $code[] = Op::I64_SHL; break;
                case 0x87: $code[] = Op::I64_SHR_S; break;
                case 0x88: $code[] = Op::I64_SHR_U; break;
                case 0x89: $code[] = Op::I64_ROTL; break;
                case 0x8A: $code[] = Op::I64_ROTR; break;

                // ---- f32 arithmetic ----
                case 0x8B: $code[] = Op::F32_ABS; break;
                case 0x8C: $code[] = Op::F32_NEG; break;
                case 0x8D: $code[] = Op::F32_CEIL; break;
                case 0x8E: $code[] = Op::F32_FLOOR; break;
                case 0x8F: $code[] = Op::F32_TRUNC; break;
                case 0x90: $code[] = Op::F32_NEAREST; break;
                case 0x91: $code[] = Op::F32_SQRT; break;
                case 0x92: $code[] = Op::F32_ADD; break;
                case 0x93: $code[] = Op::F32_SUB; break;
                case 0x94: $code[] = Op::F32_MUL; break;
                case 0x95: $code[] = Op::F32_DIV; break;
                case 0x96: $code[] = Op::F32_MIN; break;
                case 0x97: $code[] = Op::F32_MAX; break;
                case 0x98: $code[] = Op::F32_COPYSIGN; break;

                // ---- f64 arithmetic ----
                case 0x99: $code[] = Op::F64_ABS; break;
                case 0x9A: $code[] = Op::F64_NEG; break;
                case 0x9B: $code[] = Op::F64_CEIL; break;
                case 0x9C: $code[] = Op::F64_FLOOR; break;
                case 0x9D: $code[] = Op::F64_TRUNC; break;
                case 0x9E: $code[] = Op::F64_NEAREST; break;
                case 0x9F: $code[] = Op::F64_SQRT; break;
                case 0xA0: $code[] = Op::F64_ADD; break;
                case 0xA1: $code[] = Op::F64_SUB; break;
                case 0xA2: $code[] = Op::F64_MUL; break;
                case 0xA3: $code[] = Op::F64_DIV; break;
                case 0xA4: $code[] = Op::F64_MIN; break;
                case 0xA5: $code[] = Op::F64_MAX; break;
                case 0xA6: $code[] = Op::F64_COPYSIGN; break;

                // ---- Conversions ----
                case 0xA7: $code[] = Op::I32_WRAP_I64; break;
                case 0xA8: $code[] = Op::I32_TRUNC_F32_S; break;
                case 0xA9: $code[] = Op::I32_TRUNC_F32_U; break;
                case 0xAA: $code[] = Op::I32_TRUNC_F64_S; break;
                case 0xAB: $code[] = Op::I32_TRUNC_F64_U; break;
                case 0xAC: $code[] = Op::I64_EXTEND_I32_S; break;
                case 0xAD: $code[] = Op::I64_EXTEND_I32_U; break;
                case 0xAE: $code[] = Op::I64_TRUNC_F32_S; break;
                case 0xAF: $code[] = Op::I64_TRUNC_F32_U; break;
                case 0xB0: $code[] = Op::I64_TRUNC_F64_S; break;
                case 0xB1: $code[] = Op::I64_TRUNC_F64_U; break;
                case 0xB2: $code[] = Op::F32_CONVERT_I32_S; break;
                case 0xB3: $code[] = Op::F32_CONVERT_I32_U; break;
                case 0xB4: $code[] = Op::F32_CONVERT_I64_S; break;
                case 0xB5: $code[] = Op::F32_CONVERT_I64_U; break;
                case 0xB6: $code[] = Op::F32_DEMOTE_F64; break;
                case 0xB7: $code[] = Op::F64_CONVERT_I32_S; break;
                case 0xB8: $code[] = Op::F64_CONVERT_I32_U; break;
                case 0xB9: $code[] = Op::F64_CONVERT_I64_S; break;
                case 0xBA: $code[] = Op::F64_CONVERT_I64_U; break;
                case 0xBB: $code[] = Op::F64_PROMOTE_F32; break;

                // ---- Reinterpret ----
                case 0xBC: $code[] = Op::I32_REINTERPRET_F32; break;
                case 0xBD: $code[] = Op::I64_REINTERPRET_F64; break;
                case 0xBE: $code[] = Op::F32_REINTERPRET_I32; break;
                case 0xBF: $code[] = Op::F64_REINTERPRET_I64; break;

                // ---- Sign extension ----
                case 0xC0: $code[] = Op::I32_EXTEND8_S; break;
                case 0xC1: $code[] = Op::I32_EXTEND16_S; break;
                case 0xC2: $code[] = Op::I64_EXTEND8_S; break;
                case 0xC3: $code[] = Op::I64_EXTEND16_S; break;
                case 0xC4: $code[] = Op::I64_EXTEND32_S; break;

                // ---- References ----
                case 0xD0: $this->readHeapType($r); $code[] = Op::REF_NULL; break;
                case 0xD1: $code[] = Op::REF_IS_NULL; break;
                case 0xD2: $code[] = Op::REF_FUNC; $code[] = $r->readU32(); break;

                // ---- Multi-byte prefix (0xFC) ----
                case 0xFC: $this->decodeFCPrefixed($r, $code); break;

                default:
                    throw new WasmError("unknown opcode: 0x" . dechex($opcode));
            }
        }

        return $code;
    }

    private function fixupIf(array &$code, array $frame, int $endIp): void
    {
        $elseIp = $frame[2];
        if ($elseIp !== null) {
            // if with else: Op::IF_, paramCount, resultCount, elseIp, endIp
            $code[$frame[1] + 3] = $elseIp;   // elseIp
            $code[$frame[1] + 4] = $endIp;     // endIp
            $code[$elseIp + 1] = $endIp;       // else's endIp
        } else {
            // if without else
            $code[$frame[1] + 3] = $endIp;     // elseIp = endIp
            $code[$frame[1] + 4] = $endIp;     // endIp
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
     * Decode a single instruction into flat bytecode and append to $code.
     */
    private function decodeInstruction(int $opcode, BinaryReader $r, array &$code, array &$controlStack): void
    {
        switch ($opcode) {
            // ---- Control flow ----
            case 0x00: $code[] = Op::UNREACHABLE; break;
            case 0x01: break; // NOP — skip, no-op needs no dispatch slot

            case 0x02: // block
                $bt = $this->decodeBlockType($r);
                $ip = count($code);
                $code[] = Op::BLOCK;
                $code[] = $bt ? count($bt->params)  : 0; // paramCount
                $code[] = $bt ? count($bt->results) : 0; // resultCount
                $code[] = -1; // endIp placeholder
                $controlStack[] = ['block', $ip, null];
                break;

            case 0x03: // loop
                $bt = $this->decodeBlockType($r);
                $paramCount = $bt ? count($bt->params) : 0;
                $ip = count($code);
                $code[] = Op::LOOP;
                $code[] = $paramCount;  // paramCount (also = result arity for BR-to-loop)
                $code[] = $ip + 3;      // contIp = first body instruction
                $controlStack[] = ['loop', $ip, null];
                break;

            case 0x04: // if
                $bt = $this->decodeBlockType($r);
                $ip = count($code);
                $code[] = Op::IF_;
                $code[] = $bt ? count($bt->params)  : 0; // paramCount
                $code[] = $bt ? count($bt->results) : 0; // resultCount
                $code[] = -1; // elseIp placeholder
                $code[] = -1; // endIp placeholder
                $controlStack[] = ['if', $ip, null];
                break;

            // ---- Branch ----
            case 0x0C: { // BR — emit fast loop-continue variant when possible
                $brDepth = $r->readU32();
                if ($brDepth === 0) { $csLen = count($controlStack); if ($csLen > 0) { $topF = $controlStack[$csLen - 1]; if ($topF[0] === 'loop' && $code[$topF[1] + 1] === 0) { $code[] = Op::SB_BR_LOOP; $code[] = $code[$topF[1] + 2]; break; } } }
                $code[] = Op::BR; $code[] = $brDepth; break;
            }
            case 0x0D: {
                $brDepth = $r->readU32(); $csLen = count($controlStack);
                if ($brDepth===0 && $csLen>0 && $controlStack[$csLen-1][0]==='loop' && $code[$controlStack[$csLen-1][1]+1]===0) { $code[] = Op::SB_BRIF_LOOP; $code[] = $code[$controlStack[$csLen-1][1]+2]; break; }
                $code[] = Op::BR_IF; $code[] = $brDepth; break;
            }

            case 0x0E: { // br_table
                $labels = $r->readVec(fn() => $r->readU32());
                $default = $r->readU32();
                // Specialize to SB_BR_TABLE_VOID if all targets are 0-result blocks
                $_csLen = count($controlStack); $_allVoid = true;
                foreach ($labels as $_d) { $_ti=$_csLen-1-$_d; if($_ti<0||$controlStack[$_ti][0]!=='block'||$code[$controlStack[$_ti][1]+2]!==0){$_allVoid=false;break;} }
                if ($_allVoid) { $_ti=$_csLen-1-$default; if($_ti<0||$controlStack[$_ti][0]!=='block'||$code[$controlStack[$_ti][1]+2]!==0){$_allVoid=false;} }
                $code[] = $_allVoid ? Op::SB_BR_TABLE_VOID : Op::BR_TABLE;
                $code[] = count($labels); // label count
                foreach ($labels as $l) $code[] = $l;
                $code[] = $default;
                break;
            }

            case 0x0F: $code[] = Op::RETURN_; break;

            // ---- Calls ----
            case 0x10: $code[] = Op::CALL; $code[] = $r->readU32(); break;

            case 0x11: // call_indirect
                $typeIdx  = $r->readU32();
                $tableIdx = $r->readU32();
                $code[] = Op::CALL_INDIRECT; $code[] = $typeIdx; $code[] = $tableIdx;
                break;

            case 0x12: $code[] = Op::RETURN_CALL; $code[] = $r->readU32(); break;

            case 0x13: // return_call_indirect
                $typeIdx  = $r->readU32();
                $tableIdx = $r->readU32();
                $code[] = Op::RETURN_CALL_INDIRECT; $code[] = $typeIdx; $code[] = $tableIdx;
                break;

            // ---- Stack ----
            case 0x1A: $code[] = Op::DROP; break;

            case 0x1B: $code[] = Op::SELECT; break;

            case 0x1C: // select (typed)
                $r->readVec(fn() => $this->readValType($r));
                $code[] = Op::SELECT;
                break;

            // ---- Variables ----
                case 0x20: { // LOCAL_GET — peephole for common successors
                    $localIdx = $r->readU32();
                    if (!$r->eof()) {
                        $nb = $r->peekByte();
                        if ($nb === 0x20) { // LOCAL_GET follows
                            $r->readByte();
                            $localIdx2=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32STORE;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_LGET_I32LOAD;$code[]=$localIdx;$code[]=$localIdx2;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x6A){$r->readByte();$code[]=Op::SB_LGET_LGET_I32ADD;$code[]=$localIdx;$code[]=$localIdx2;break;}
                            $code[] = Op::SB_LGET_LGET; $code[] = $localIdx; $code[] = $localIdx2;
                            break;
                        }
                        if ($nb === 0x41) { // I32_CONST follows → check for I32_ADD triple
                            $r->readByte();
                            $constVal = $r->readS32();
                            if (!$r->eof() && $r->peekByte() === 0x6A) { // I32_ADD
                                $r->readByte();
                                if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_ICONST_IADD_LSET; $code[] = $localIdx; $code[] = $constVal; $code[] = $r->readU32(); break; }
                                if (!$r->eof() && $r->peekByte() === 0x22) { $r->readByte(); $teeIdx2=$r->readU32(); if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brD2=$r->readU32();$csLen2=count($controlStack);if($brD2===0&&$csLen2>0){$topF2=$controlStack[$csLen2-1];if($topF2[0]==='loop'&&$code[$topF2[1]+1]===0){$code[]=Op::SB_LGET_ICONST_IADD_LTEE_BRIF_LOOP;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$code[$topF2[1]+2];break;}}$code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=Op::BR_IF;$code[]=$brD2;break;}if(!$r->eof()&&$r->peekByte()===0x28){$r->readByte();$r->readU32();$ldOff2=$r->readU32();$code[]=Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;$code[]=$ldOff2;break;}$code[]=Op::SB_LGET_ICONST_IADD_LTEE;$code[]=$localIdx;$code[]=$constVal;$code[]=$teeIdx2;break; }
                                $code[] = Op::SB_LGET_ICONST_IADD; $code[] = $localIdx; $code[] = $constVal;
                                break;
                            }
                            if(!$r->eof()&&$r->peekByte()===0x4A){$r->readByte();if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDg=$r->readU32();$csLng=count($controlStack);if($brDg===0&&$csLng>0&&$controlStack[$csLng-1][0]==='loop'&&$code[$controlStack[$csLng-1][1]+1]===0){$code[]=Op::SB_LGET_ICONST_I32GTS_BRIF_LOOP;$code[]=$localIdx;$code[]=$constVal;$code[]=$code[$controlStack[$csLng-1][1]+2];break;}$code[]=Op::SB_LGET_ICONST_I32GTS_BRIF;$code[]=$localIdx;$code[]=$constVal;$code[]=$brDg;break;}$code[]=Op::SB_LGET_ICONST;$code[]=$localIdx;$code[]=$constVal;$code[]=Op::I32_GT_S;break;}
                            if(!$r->eof()&&$r->peekByte()===0x71){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32AND;$code[]=$localIdx;$code[]=$constVal;break;}
                            if(!$r->eof()&&$r->peekByte()===0x74){$r->readByte();$code[]=Op::SB_LGET_ICONST_I32SHL;$code[]=$localIdx;$code[]=$constVal;break;}
                            if(!$r->eof()&&$r->peekByte()===0x36){$r->readByte();$r->readU32();$code[]=Op::SB_LGET_ICONST_I32STORE;$code[]=$localIdx;$code[]=$constVal;$code[]=$r->readU32();break;}
                            $code[] = Op::SB_LGET_ICONST; $code[] = $localIdx; $code[] = $constVal;
                            break;
                        }
                        if ($nb === 0x6A) { $r->readByte(); $code[] = Op::SB_LGET_I32ADD; $code[] = $localIdx; break; } // I32_ADD follows
                        if ($nb === 0x29) { // I64_LOAD follows
                            $r->readByte(); $r->readU32(); $code[] = Op::SB_LGET_I64LOAD; $code[] = $localIdx; $code[] = $r->readU32(); break;
                        }
                        if ($nb === 0x42) { // I64_CONST follows — fuse with i64.lt_u + br_if
                            $r->readByte(); $c64g=$r->readS64();
                            if(!$r->eof()&&$r->peekByte()===0x54){$r->readByte();if(!$r->eof()&&$r->peekByte()===0x0D){$r->readByte();$brDlg=$r->readU32();$csLlg=count($controlStack);if($brDlg===0&&$csLlg>0&&$controlStack[$csLlg-1][0]==='loop'&&$code[$controlStack[$csLlg-1][1]+1]===0){$code[]=Op::SB_LGET_I64CONST_I64LTU_BRIF_LOOP;$code[]=$localIdx;$code[]=$c64g;$code[]=$code[$controlStack[$csLlg-1][1]+2];break;}$code[]=Op::SB_LGET_I64CONST_I64LTU_BRIF;$code[]=$localIdx;$code[]=$c64g;$code[]=$brDlg;break;}$code[]=Op::LOCAL_GET;$code[]=$localIdx;$code[]=Op::I64_CONST;$code[]=$c64g;$code[]=Op::I64_LT_U;break;}
                            if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_LGET_I64CONST_I64AND;$code[]=$localIdx;$code[]=$c64g;break;}
                            $code[]=Op::SB_LGET_I64CONST;$code[]=$localIdx;$code[]=$c64g;break;
                        }
                        if ($nb === 0x28) { // I32_LOAD follows
                            $r->readByte();
                            $r->readU32(); // skip alignment
                            $offset = $r->readU32();
                            if (!$r->eof() && $r->peekByte() === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_I32LOAD_LSET; $code[] = $localIdx; $code[] = $offset; $code[] = $r->readU32(); break; }
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $teeIdx = $r->readU32();
                                $code[] = Op::SB_LGET_I32LOAD_LTEE; $code[] = $localIdx; $code[] = $offset; $code[] = $teeIdx;
                                break;
                            }
                            $code[] = Op::SB_LGET_I32LOAD; $code[] = $localIdx; $code[] = $offset;
                            break;
                        }
                        if ($nb === 0x2D) { // I32_LOAD8_U follows
                            $r->readByte();
                            $r->readU32(); // skip alignment
                            $offset = $r->readU32();
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $teeIdx = $r->readU32();
                                $code[] = Op::SB_LGET_I32LOAD8U_LTEE; $code[] = $localIdx; $code[] = $offset; $code[] = $teeIdx;
                                break;
                            }
                            $code[] = Op::SB_LGET_I32LOAD8U; $code[] = $localIdx; $code[] = $offset;
                            break;
                        }
                        if ($nb === 0xA7) { // I32_WRAP_I64 follows → check for LOCAL_TEE triple
                            $r->readByte();
                            if (!$r->eof() && $r->peekByte() === 0x22) { // LOCAL_TEE follows
                                $r->readByte();
                                $code[] = Op::SB_LGET_I32WRAP_LTEE; $code[] = $localIdx; $code[] = $r->readU32();
                                break;
                            }
                            $code[] = Op::SB_LGET_I32WRAP; $code[] = $localIdx;
                            break;
                        }
                        if ($nb === 0x21) { $r->readByte(); $code[] = Op::SB_LGET_LSET; $code[] = $localIdx; $code[] = $r->readU32(); break; }
                        if ($nb === 0x6B) { $r->readByte(); $code[] = Op::SB_LGET_I32SUB; $code[] = $localIdx; break; } // I32_SUB follows
                    }
                    $code[] = Op::LOCAL_GET; $code[] = $localIdx;
                    break;
                }
            case 0x21: $code[] = Op::LOCAL_SET;  $code[] = $r->readU32(); break;
            case 0x22: { $teeIdx=$r->readU32(); if(!$r->eof()){$nb2=$r->peekByte();if($nb2===0x41){$r->readByte();$code[]=Op::SB_LTEE_ICONST;$code[]=$teeIdx;$code[]=$r->readS32();break;}if($nb2===0x42){$r->readByte();$code[]=Op::SB_LTEE_I64CONST;$code[]=$teeIdx;$code[]=$r->readS64();break;}if($nb2===0x0D){$r->readByte();$code[]=Op::SB_LTEE_BRIF;$code[]=$teeIdx;$code[]=$r->readU32();break;}} $code[]=Op::LOCAL_TEE;$code[]=$teeIdx;break; }
            case 0x23: $code[] = Op::GLOBAL_GET; $code[] = $r->readU32(); break;
            case 0x24: $code[] = Op::GLOBAL_SET; $code[] = $r->readU32(); break;

            // ---- Table ----
            case 0x25: $code[] = Op::TABLE_GET; $code[] = $r->readU32(); break;
            case 0x26: $code[] = Op::TABLE_SET; $code[] = $r->readU32(); break;

            // ---- Memory load ----
            case 0x28: { $off=$this->readMemArg($r); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();break;} $code[]=Op::I32_LOAD;$code[]=$off;break; }
            case 0x29: { $off=$this->readMemArg($r); if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I64LOAD_LTEE;$code[]=$off;$code[]=$r->readU32();break;} $code[]=Op::I64_LOAD;$code[]=$off;break; }
            case 0x2A: $code[] = Op::F32_LOAD;     $code[] = $this->readMemArg($r); break;
            case 0x2B: $code[] = Op::F64_LOAD;     $code[] = $this->readMemArg($r); break;
            case 0x2C: $code[] = Op::I32_LOAD8_S;  $code[] = $this->readMemArg($r); break;
            case 0x2D: $code[] = Op::I32_LOAD8_U;  $code[] = $this->readMemArg($r); break;
            case 0x2E: $code[] = Op::I32_LOAD16_S; $code[] = $this->readMemArg($r); break;
            case 0x2F: $code[] = Op::I32_LOAD16_U; $code[] = $this->readMemArg($r); break;
            case 0x30: $code[] = Op::I64_LOAD8_S;  $code[] = $this->readMemArg($r); break;
            case 0x31: $code[] = Op::I64_LOAD8_U;  $code[] = $this->readMemArg($r); break;
            case 0x32: $code[] = Op::I64_LOAD16_S; $code[] = $this->readMemArg($r); break;
            case 0x33: $code[] = Op::I64_LOAD16_U; $code[] = $this->readMemArg($r); break;
            case 0x34: $code[] = Op::I64_LOAD32_S; $code[] = $this->readMemArg($r); break;
            case 0x35: $code[] = Op::I64_LOAD32_U; $code[] = $this->readMemArg($r); break;

            // ---- Memory store ----
            case 0x36: $code[] = Op::I32_STORE;   $code[] = $this->readMemArg($r); break;
            case 0x37: $code[] = Op::I64_STORE;   $code[] = $this->readMemArg($r); break;
            case 0x38: $code[] = Op::F32_STORE;   $code[] = $this->readMemArg($r); break;
            case 0x39: $code[] = Op::F64_STORE;   $code[] = $this->readMemArg($r); break;
            case 0x3A: $code[] = Op::I32_STORE8;  $code[] = $this->readMemArg($r); break;
            case 0x3B: $code[] = Op::I32_STORE16; $code[] = $this->readMemArg($r); break;
            case 0x3C: $code[] = Op::I64_STORE8;  $code[] = $this->readMemArg($r); break;
            case 0x3D: $code[] = Op::I64_STORE16; $code[] = $this->readMemArg($r); break;
            case 0x3E: $code[] = Op::I64_STORE32; $code[] = $this->readMemArg($r); break;

            // ---- Memory management ----
            case 0x3F:
                $r->readByte(); // memory index (0x00)
                $code[] = Op::MEMORY_SIZE;
                break;
            case 0x40:
                $r->readByte(); // memory index (0x00)
                $code[] = Op::MEMORY_GROW;
                break;

            // ---- Constants ----
            case 0x41: {
                $constVal = $r->readS32();
                if (!$r->eof() && $r->peekByte() === 0x6A) { // I32_ADD follows
                    $r->readByte();
                    $code[] = Op::SB_ICONST_IADD; $code[] = $constVal;
                    break;
                }
                if (!$r->eof() && $r->peekByte() === 0x74) { $r->readByte(); $code[] = Op::SB_ICONST_I32SHL; $code[] = $constVal; break; }
                $code[] = Op::I32_CONST; $code[] = $constVal; break;
            }
            case 0x42: { $c64=$r->readS64(); if(!$r->eof()&&$r->peekByte()===0x37){$r->readByte();$r->readU32();$code[]=Op::SB_I64CONST_I64STORE;$code[]=$c64;$code[]=$r->readU32();break;} if(!$r->eof()&&$r->peekByte()===0x83){$r->readByte();$code[]=Op::SB_I64CONST_I64AND;$code[]=$c64;break;} $code[]=Op::I64_CONST;$code[]=$c64;break; }
            case 0x43: $code[] = Op::F32_CONST; $code[] = $r->readF32(); break;
            case 0x44: $code[] = Op::F64_CONST; $code[] = $r->readF64(); break;

            // ---- i32 comparison ----
                case 0x45: // I32_EQZ — peephole for I32_EQZ + BR_IF
                    if (!$r->eof() && $r->peekByte() === 0x0D) {
                        $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack);
                        if ($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0) { $code[]=Op::SB_I32EQZ_BRIF_LOOP; $code[]=$code[$controlStack[$csLen-1][1]+2]; break; }
                        $code[] = Op::SB_I32EQZ_BRIF; $code[] = $brDepth; break;
                    }
                    $code[] = Op::I32_EQZ;
                    break;
            case 0x46:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32EQ_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32EQ_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_EQ; break;
            case 0x47:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32NE_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32NE_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_NE; break;
            case 0x48:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LTS_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LTS_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_LT_S; break;
            case 0x49:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LTU_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_LT_U; break;
            case 0x4A:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GTS_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GTS_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_GT_S; break;
            case 0x4B:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GTU_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_GT_U; break;
            case 0x4C:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LES_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LES_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_LE_S; break;
            case 0x4D:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32LEU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32LEU_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_LE_U; break;
            case 0x4E:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GES_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GES_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_GE_S; break;
            case 0x4F:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I32GEU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I32GEU_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I32_GE_U; break;

            // ---- i64 comparison ----
            case 0x50: $code[] = Op::I64_EQZ; break;
            case 0x51:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64EQ_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64EQ_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I64_EQ; break;
            case 0x52:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64NE_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64NE_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I64_NE; break;
            case 0x53: $code[] = Op::I64_LT_S; break;
            case 0x54:
                if (!$r->eof() && $r->peekByte() === 0x0D) { $r->readByte(); $brDepth=$r->readU32(); $csLen=count($controlStack); if($brDepth===0&&$csLen>0&&$controlStack[$csLen-1][0]==='loop'&&$code[$controlStack[$csLen-1][1]+1]===0){$code[]=Op::SB_I64LTU_BRIF_LOOP;$code[]=$code[$controlStack[$csLen-1][1]+2];break;} $code[]=Op::SB_I64LTU_BRIF;$code[]=$brDepth;break; }
                $code[] = Op::I64_LT_U; break;
            case 0x55: $code[] = Op::I64_GT_S; break;
            case 0x56: $code[] = Op::I64_GT_U; break;
            case 0x57: $code[] = Op::I64_LE_S; break;
            case 0x58: $code[] = Op::I64_LE_U; break;
            case 0x59: $code[] = Op::I64_GE_S; break;
            case 0x5A: $code[] = Op::I64_GE_U; break;

            // ---- f32 comparison ----
            case 0x5B: $code[] = Op::F32_EQ; break;
            case 0x5C: $code[] = Op::F32_NE; break;
            case 0x5D: $code[] = Op::F32_LT; break;
            case 0x5E: $code[] = Op::F32_GT; break;
            case 0x5F: $code[] = Op::F32_LE; break;
            case 0x60: $code[] = Op::F32_GE; break;

            // ---- f64 comparison ----
            case 0x61: $code[] = Op::F64_EQ; break;
            case 0x62: $code[] = Op::F64_NE; break;
            case 0x63: $code[] = Op::F64_LT; break;
            case 0x64: $code[] = Op::F64_GT; break;
            case 0x65: $code[] = Op::F64_LE; break;
            case 0x66: $code[] = Op::F64_GE; break;

            // ---- i32 arithmetic ----
            case 0x67: $code[] = Op::I32_CLZ; break;
            case 0x68: $code[] = Op::I32_CTZ; break;
            case 0x69: $code[] = Op::I32_POPCNT; break;
            case 0x6A: $code[] = Op::I32_ADD; break;
            case 0x6B: { if(!$r->eof()&&$r->peekByte()===0x22){$r->readByte();$code[]=Op::SB_I32SUB_LTEE;$code[]=$r->readU32();break;} $code[]=Op::I32_SUB;break; }
            case 0x6C: $code[] = Op::I32_MUL; break;
            case 0x6D: $code[] = Op::I32_DIV_S; break;
            case 0x6E: $code[] = Op::I32_DIV_U; break;
            case 0x6F: $code[] = Op::I32_REM_S; break;
            case 0x70: $code[] = Op::I32_REM_U; break;
            case 0x71: $code[] = Op::I32_AND; break;
            case 0x72: $code[] = Op::I32_OR; break;
            case 0x73: $code[] = Op::I32_XOR; break;
            case 0x74: $code[] = Op::I32_SHL; break;
            case 0x75: $code[] = Op::I32_SHR_S; break;
            case 0x76: $code[] = Op::I32_SHR_U; break;
            case 0x77: $code[] = Op::I32_ROTL; break;
            case 0x78: $code[] = Op::I32_ROTR; break;

            // ---- i64 arithmetic ----
            case 0x79: $code[] = Op::I64_CLZ; break;
            case 0x7A: $code[] = Op::I64_CTZ; break;
            case 0x7B: $code[] = Op::I64_POPCNT; break;
            case 0x7C: $code[] = Op::I64_ADD; break;
            case 0x7D: $code[] = Op::I64_SUB; break;
            case 0x7E: $code[] = Op::I64_MUL; break;
            case 0x7F: $code[] = Op::I64_DIV_S; break;
            case 0x80: $code[] = Op::I64_DIV_U; break;
            case 0x81: $code[] = Op::I64_REM_S; break;
            case 0x82: $code[] = Op::I64_REM_U; break;
            case 0x83: $code[] = Op::I64_AND; break;
            case 0x84: $code[] = Op::I64_OR; break;
            case 0x85: $code[] = Op::I64_XOR; break;
            case 0x86: $code[] = Op::I64_SHL; break;
            case 0x87: $code[] = Op::I64_SHR_S; break;
            case 0x88: $code[] = Op::I64_SHR_U; break;
            case 0x89: $code[] = Op::I64_ROTL; break;
            case 0x8A: $code[] = Op::I64_ROTR; break;

            // ---- f32 arithmetic ----
            case 0x8B: $code[] = Op::F32_ABS; break;
            case 0x8C: $code[] = Op::F32_NEG; break;
            case 0x8D: $code[] = Op::F32_CEIL; break;
            case 0x8E: $code[] = Op::F32_FLOOR; break;
            case 0x8F: $code[] = Op::F32_TRUNC; break;
            case 0x90: $code[] = Op::F32_NEAREST; break;
            case 0x91: $code[] = Op::F32_SQRT; break;
            case 0x92: $code[] = Op::F32_ADD; break;
            case 0x93: $code[] = Op::F32_SUB; break;
            case 0x94: $code[] = Op::F32_MUL; break;
            case 0x95: $code[] = Op::F32_DIV; break;
            case 0x96: $code[] = Op::F32_MIN; break;
            case 0x97: $code[] = Op::F32_MAX; break;
            case 0x98: $code[] = Op::F32_COPYSIGN; break;

            // ---- f64 arithmetic ----
            case 0x99: $code[] = Op::F64_ABS; break;
            case 0x9A: $code[] = Op::F64_NEG; break;
            case 0x9B: $code[] = Op::F64_CEIL; break;
            case 0x9C: $code[] = Op::F64_FLOOR; break;
            case 0x9D: $code[] = Op::F64_TRUNC; break;
            case 0x9E: $code[] = Op::F64_NEAREST; break;
            case 0x9F: $code[] = Op::F64_SQRT; break;
            case 0xA0: $code[] = Op::F64_ADD; break;
            case 0xA1: $code[] = Op::F64_SUB; break;
            case 0xA2: $code[] = Op::F64_MUL; break;
            case 0xA3: $code[] = Op::F64_DIV; break;
            case 0xA4: $code[] = Op::F64_MIN; break;
            case 0xA5: $code[] = Op::F64_MAX; break;
            case 0xA6: $code[] = Op::F64_COPYSIGN; break;

            // ---- Conversions ----
            case 0xA7: $code[] = Op::I32_WRAP_I64; break;
            case 0xA8: $code[] = Op::I32_TRUNC_F32_S; break;
            case 0xA9: $code[] = Op::I32_TRUNC_F32_U; break;
            case 0xAA: $code[] = Op::I32_TRUNC_F64_S; break;
            case 0xAB: $code[] = Op::I32_TRUNC_F64_U; break;
            case 0xAC: $code[] = Op::I64_EXTEND_I32_S; break;
            case 0xAD: $code[] = Op::I64_EXTEND_I32_U; break;
            case 0xAE: $code[] = Op::I64_TRUNC_F32_S; break;
            case 0xAF: $code[] = Op::I64_TRUNC_F32_U; break;
            case 0xB0: $code[] = Op::I64_TRUNC_F64_S; break;
            case 0xB1: $code[] = Op::I64_TRUNC_F64_U; break;
            case 0xB2: $code[] = Op::F32_CONVERT_I32_S; break;
            case 0xB3: $code[] = Op::F32_CONVERT_I32_U; break;
            case 0xB4: $code[] = Op::F32_CONVERT_I64_S; break;
            case 0xB5: $code[] = Op::F32_CONVERT_I64_U; break;
            case 0xB6: $code[] = Op::F32_DEMOTE_F64; break;
            case 0xB7: $code[] = Op::F64_CONVERT_I32_S; break;
            case 0xB8: $code[] = Op::F64_CONVERT_I32_U; break;
            case 0xB9: $code[] = Op::F64_CONVERT_I64_S; break;
            case 0xBA: $code[] = Op::F64_CONVERT_I64_U; break;
            case 0xBB: $code[] = Op::F64_PROMOTE_F32; break;

            // ---- Reinterpret ----
            case 0xBC: $code[] = Op::I32_REINTERPRET_F32; break;
            case 0xBD: $code[] = Op::I64_REINTERPRET_F64; break;
            case 0xBE: $code[] = Op::F32_REINTERPRET_I32; break;
            case 0xBF: $code[] = Op::F64_REINTERPRET_I64; break;

            // ---- Sign extension ----
            case 0xC0: $code[] = Op::I32_EXTEND8_S; break;
            case 0xC1: $code[] = Op::I32_EXTEND16_S; break;
            case 0xC2: $code[] = Op::I64_EXTEND8_S; break;
            case 0xC3: $code[] = Op::I64_EXTEND16_S; break;
            case 0xC4: $code[] = Op::I64_EXTEND32_S; break;

            // ---- References ----
            case 0xD0: // ref.null
                $this->readHeapType($r);
                $code[] = Op::REF_NULL;
                break;
            case 0xD1: $code[] = Op::REF_IS_NULL; break;
            case 0xD2: $code[] = Op::REF_FUNC; $code[] = $r->readU32(); break;

            // ---- Multi-byte prefix (0xFC) ----
            case 0xFC:
                $this->decodeFCPrefixed($r, $code);
                break;

            default:
                throw new WasmError("unknown opcode: 0x" . dechex($opcode));
        }
    }

    /**
     * Decode 0xFC-prefixed opcodes into flat bytecode.
     */
    private function decodeFCPrefixed(BinaryReader $r, array &$code): void
    {
        $sub = $r->readU32();
        match ($sub) {
            // Saturating truncation
            0  => $code[] = Op::I32_TRUNC_SAT_F32_S,
            1  => $code[] = Op::I32_TRUNC_SAT_F32_U,
            2  => $code[] = Op::I32_TRUNC_SAT_F64_S,
            3  => $code[] = Op::I32_TRUNC_SAT_F64_U,
            4  => $code[] = Op::I64_TRUNC_SAT_F32_S,
            5  => $code[] = Op::I64_TRUNC_SAT_F32_U,
            6  => $code[] = Op::I64_TRUNC_SAT_F64_S,
            7  => $code[] = Op::I64_TRUNC_SAT_F64_U,

            // Bulk memory operations
            8  => $this->decodeMemoryInit($r, $code),
            9  => $this->decodeDataDrop($r, $code),
            10 => $this->decodeMemoryCopy($r, $code),
            11 => $this->decodeMemoryFill($r, $code),

            // Table operations
            12 => $this->decodeTableInit($r, $code),
            13 => $this->decodeElemDrop($r, $code),
            14 => $this->decodeTableCopy($r, $code),
            15 => $this->decodeTableGrow($r, $code),
            16 => $this->decodeTableSize($r, $code),
            17 => $this->decodeTableFill($r, $code),

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
