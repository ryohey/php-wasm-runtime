<?php

declare(strict_types=1);

namespace WasmRuntime\Binary;

use WasmRuntime\{FuncType, Module, Profiler, ValType, WasmError, WasmValue};

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
        Profiler::enter('decoder.decode');
        try {
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
                $sectionId = $r->readByte();
                $sectionLen = $r->readU32();
                $sub = $r->subReader($sectionLen);
                $sectionStart = hrtime(true);

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

                $elapsed = hrtime(true) - $sectionStart;
                Profiler::addSectionDuration('decoder.section.' . self::sectionName($sectionId), $elapsed);
            }

            return $this->mod;
        } finally {
            Profiler::leave('decoder.decode');
        }
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
                0x41 => $ops[] = ['i32.const', $r->readS32()],
                0x42 => $ops[] = ['i64.const', $r->readS64()],
                0x43 => $ops[] = ['f32.const', $this->decodeConstF32($r)],
                0x44 => $ops[] = ['f64.const', WasmValue::f64($r->readF64())],
                0x23 => (function() use ($r, &$ops, &$hasGlobalGet) {
                    $ops[] = ['global.get', $r->readU32()];
                    $hasGlobalGet = true;
                })(),
                0xD0 => $ops[] = ['ref.null', $this->decodeConstRefNull($r)],
                0xD2 => $ops[] = ['ref.func', $this->decodeConstRefFunc($r)],
                0x6A => $ops[] = ['i32.add'],
                0x6B => $ops[] = ['i32.sub'],
                0x6C => $ops[] = ['i32.mul'],
                0x7C => $ops[] = ['i64.add'],
                0x7D => $ops[] = ['i64.sub'],
                0x7E => $ops[] = ['i64.mul'],
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
                'i32.const' => $stack[] = WasmValue::i32($op[1]),
                'i64.const' => $stack[] = WasmValue::i64($op[1]),
                'f32.const' => $stack[] = $op[1],
                'f64.const' => $stack[] = $op[1],
                'global.get' => $stack[] = self::resolveGlobalGetForConst($op[1], $globals),
                'ref.null'  => $stack[] = $op[1],
                'ref.func'  => $stack[] = $op[1],
                'i32.add' => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a + $b)),
                'i32.sub' => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a - $b)),
                'i32.mul' => self::constBinOp($stack, ValType::I32, fn($a, $b) => WasmValue::mask32($a * $b)),
                'i64.add' => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a + $b),
                'i64.sub' => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a - $b),
                'i64.mul' => self::constBinOp($stack, ValType::I64, fn($a, $b) => $a * $b),
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

        // Decode instructions into flat bytecode with pre-computed IP targets
        $code = $this->decodeExpr($r);

        return ['locals' => $locals, 'code' => $code];
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
    private function decodeExpr(BinaryReader $r): array
    {
        $code = [];

        // Fixup stack for control flow: each entry is
        //   ['kind' => 'block'|'loop'|'if', 'ip' => int, 'elseIp' => ?int]
        $controlStack = [];

        while (!$r->eof()) {
            $opcode = $r->readByte();

            if ($opcode === 0x0B) {
                // end
                if (empty($controlStack)) {
                    // End of the function/expression — emit final end
                    $code[] = ['end'];
                    break;
                }
                $frame = array_pop($controlStack);
                $endIp = count($code);

                // Fixup the opening instruction
                match ($frame['kind']) {
                    'block' => $code[$frame['ip']][2] = $endIp,
                    'loop'  => $code[$frame['ip']][3] = $endIp,
                    'if'    => $this->fixupIf($code, $frame, $endIp),
                };

                $code[] = ['end'];
                continue;
            }

            if ($opcode === 0x05) {
                // else
                if (empty($controlStack)) {
                    throw new WasmError('else without matching if');
                }
                $elseIp = count($code);
                $controlStack[count($controlStack) - 1]['elseIp'] = $elseIp;
                // We'll fixup endIp when we hit 'end'
                $code[] = ['else', -1]; // endIp placeholder
                continue;
            }

            $this->decodeInstruction($opcode, $r, $code, $controlStack);
        }

        return $code;
    }

    private function fixupIf(array &$code, array $frame, int $endIp): void
    {
        if ($frame['elseIp'] !== null) {
            // if with else
            $code[$frame['ip']][2] = $frame['elseIp']; // elseIp
            $code[$frame['ip']][3] = $endIp;            // endIp
            $code[$frame['elseIp']][1] = $endIp;        // else's endIp
        } else {
            // if without else
            $code[$frame['ip']][2] = $endIp; // elseIp = endIp
            $code[$frame['ip']][3] = $endIp; // endIp
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
     * Decode a single instruction and append to $code.
     */
    private function decodeInstruction(int $opcode, BinaryReader $r, array &$code, array &$controlStack): void
    {
        switch ($opcode) {
            // ---- Control flow ----
            case 0x00: $code[] = ['unreachable']; break;
            case 0x01: $code[] = ['nop']; break;

            case 0x02: // block
                $bt = $this->decodeBlockType($r);
                $ip = count($code);
                $code[] = ['block', $bt, -1]; // endIp placeholder
                $controlStack[] = ['kind' => 'block', 'ip' => $ip, 'elseIp' => null];
                break;

            case 0x03: // loop
                $bt = $this->decodeBlockType($r);
                $ip = count($code);
                $contIp = $ip + 1;
                $code[] = ['loop', $bt, $contIp, -1]; // endIp placeholder
                $controlStack[] = ['kind' => 'loop', 'ip' => $ip, 'elseIp' => null];
                break;

            case 0x04: // if
                $bt = $this->decodeBlockType($r);
                $ip = count($code);
                $code[] = ['if', $bt, -1, -1]; // elseIp, endIp placeholders
                $controlStack[] = ['kind' => 'if', 'ip' => $ip, 'elseIp' => null];
                break;

            // ---- Branch ----
            case 0x0C: $code[] = ['br', $r->readU32()]; break;
            case 0x0D: $code[] = ['br_if', $r->readU32()]; break;

            case 0x0E: // br_table
                $labels = $r->readVec(fn() => $r->readU32());
                $default = $r->readU32();
                $code[] = ['br_table', ...$labels, $default];
                break;

            case 0x0F: $code[] = ['return']; break;

            // ---- Calls ----
            case 0x10: $code[] = ['call', $r->readU32()]; break;

            case 0x11: // call_indirect
                $typeIdx  = $r->readU32();
                $tableIdx = $r->readU32();
                $code[] = ['call_indirect', $typeIdx, $tableIdx];
                break;

            case 0x12: $code[] = ['return_call', $r->readU32()]; break;

            case 0x13: // return_call_indirect
                $typeIdx  = $r->readU32();
                $tableIdx = $r->readU32();
                $code[] = ['return_call_indirect', $typeIdx, $tableIdx];
                break;

            // ---- Stack ----
            case 0x1A: $code[] = ['drop']; break;

            case 0x1B: $code[] = ['select']; break;

            case 0x1C: // select (typed)
                $r->readVec(fn() => $this->readValType($r)); // value types (ignored)
                $code[] = ['select'];
                break;

            // ---- Variables ----
            case 0x20: $code[] = ['local.get', $r->readU32()]; break;
            case 0x21: $code[] = ['local.set', $r->readU32()]; break;
            case 0x22: $code[] = ['local.tee', $r->readU32()]; break;
            case 0x23: $code[] = ['global.get', $r->readU32()]; break;
            case 0x24: $code[] = ['global.set', $r->readU32()]; break;

            // ---- Table ----
            case 0x25: $code[] = ['table.get', $r->readU32()]; break;
            case 0x26: $code[] = ['table.set', $r->readU32()]; break;

            // ---- Memory load ----
            case 0x28: $code[] = ['i32.load',    $this->readMemArg($r)]; break;
            case 0x29: $code[] = ['i64.load',    $this->readMemArg($r)]; break;
            case 0x2A: $code[] = ['f32.load',    $this->readMemArg($r)]; break;
            case 0x2B: $code[] = ['f64.load',    $this->readMemArg($r)]; break;
            case 0x2C: $code[] = ['i32.load8_s', $this->readMemArg($r)]; break;
            case 0x2D: $code[] = ['i32.load8_u', $this->readMemArg($r)]; break;
            case 0x2E: $code[] = ['i32.load16_s',$this->readMemArg($r)]; break;
            case 0x2F: $code[] = ['i32.load16_u',$this->readMemArg($r)]; break;
            case 0x30: $code[] = ['i64.load8_s', $this->readMemArg($r)]; break;
            case 0x31: $code[] = ['i64.load8_u', $this->readMemArg($r)]; break;
            case 0x32: $code[] = ['i64.load16_s',$this->readMemArg($r)]; break;
            case 0x33: $code[] = ['i64.load16_u',$this->readMemArg($r)]; break;
            case 0x34: $code[] = ['i64.load32_s',$this->readMemArg($r)]; break;
            case 0x35: $code[] = ['i64.load32_u',$this->readMemArg($r)]; break;

            // ---- Memory store ----
            case 0x36: $code[] = ['i32.store',   $this->readMemArg($r)]; break;
            case 0x37: $code[] = ['i64.store',   $this->readMemArg($r)]; break;
            case 0x38: $code[] = ['f32.store',   $this->readMemArg($r)]; break;
            case 0x39: $code[] = ['f64.store',   $this->readMemArg($r)]; break;
            case 0x3A: $code[] = ['i32.store8',  $this->readMemArg($r)]; break;
            case 0x3B: $code[] = ['i32.store16', $this->readMemArg($r)]; break;
            case 0x3C: $code[] = ['i64.store8',  $this->readMemArg($r)]; break;
            case 0x3D: $code[] = ['i64.store16', $this->readMemArg($r)]; break;
            case 0x3E: $code[] = ['i64.store32', $this->readMemArg($r)]; break;

            // ---- Memory management ----
            case 0x3F:
                $r->readByte(); // memory index (0x00)
                $code[] = ['memory.size'];
                break;
            case 0x40:
                $r->readByte(); // memory index (0x00)
                $code[] = ['memory.grow'];
                break;

            // ---- Constants ----
            case 0x41: $code[] = ['i32.const', $r->readS32()]; break;
            case 0x42: $code[] = ['i64.const', $r->readS64()]; break;
            case 0x43:
                $v = $r->readF32();
                $code[] = ['f32.const', $v]; // int for NaN, float otherwise
                break;
            case 0x44: $code[] = ['f64.const', $r->readF64()]; break;

            // ---- i32 comparison ----
            case 0x45: $code[] = ['i32.eqz']; break;
            case 0x46: $code[] = ['i32.eq']; break;
            case 0x47: $code[] = ['i32.ne']; break;
            case 0x48: $code[] = ['i32.lt_s']; break;
            case 0x49: $code[] = ['i32.lt_u']; break;
            case 0x4A: $code[] = ['i32.gt_s']; break;
            case 0x4B: $code[] = ['i32.gt_u']; break;
            case 0x4C: $code[] = ['i32.le_s']; break;
            case 0x4D: $code[] = ['i32.le_u']; break;
            case 0x4E: $code[] = ['i32.ge_s']; break;
            case 0x4F: $code[] = ['i32.ge_u']; break;

            // ---- i64 comparison ----
            case 0x50: $code[] = ['i64.eqz']; break;
            case 0x51: $code[] = ['i64.eq']; break;
            case 0x52: $code[] = ['i64.ne']; break;
            case 0x53: $code[] = ['i64.lt_s']; break;
            case 0x54: $code[] = ['i64.lt_u']; break;
            case 0x55: $code[] = ['i64.gt_s']; break;
            case 0x56: $code[] = ['i64.gt_u']; break;
            case 0x57: $code[] = ['i64.le_s']; break;
            case 0x58: $code[] = ['i64.le_u']; break;
            case 0x59: $code[] = ['i64.ge_s']; break;
            case 0x5A: $code[] = ['i64.ge_u']; break;

            // ---- f32 comparison ----
            case 0x5B: $code[] = ['f32.eq']; break;
            case 0x5C: $code[] = ['f32.ne']; break;
            case 0x5D: $code[] = ['f32.lt']; break;
            case 0x5E: $code[] = ['f32.gt']; break;
            case 0x5F: $code[] = ['f32.le']; break;
            case 0x60: $code[] = ['f32.ge']; break;

            // ---- f64 comparison ----
            case 0x61: $code[] = ['f64.eq']; break;
            case 0x62: $code[] = ['f64.ne']; break;
            case 0x63: $code[] = ['f64.lt']; break;
            case 0x64: $code[] = ['f64.gt']; break;
            case 0x65: $code[] = ['f64.le']; break;
            case 0x66: $code[] = ['f64.ge']; break;

            // ---- i32 arithmetic ----
            case 0x67: $code[] = ['i32.clz']; break;
            case 0x68: $code[] = ['i32.ctz']; break;
            case 0x69: $code[] = ['i32.popcnt']; break;
            case 0x6A: $code[] = ['i32.add']; break;
            case 0x6B: $code[] = ['i32.sub']; break;
            case 0x6C: $code[] = ['i32.mul']; break;
            case 0x6D: $code[] = ['i32.div_s']; break;
            case 0x6E: $code[] = ['i32.div_u']; break;
            case 0x6F: $code[] = ['i32.rem_s']; break;
            case 0x70: $code[] = ['i32.rem_u']; break;
            case 0x71: $code[] = ['i32.and']; break;
            case 0x72: $code[] = ['i32.or']; break;
            case 0x73: $code[] = ['i32.xor']; break;
            case 0x74: $code[] = ['i32.shl']; break;
            case 0x75: $code[] = ['i32.shr_s']; break;
            case 0x76: $code[] = ['i32.shr_u']; break;
            case 0x77: $code[] = ['i32.rotl']; break;
            case 0x78: $code[] = ['i32.rotr']; break;

            // ---- i64 arithmetic ----
            case 0x79: $code[] = ['i64.clz']; break;
            case 0x7A: $code[] = ['i64.ctz']; break;
            case 0x7B: $code[] = ['i64.popcnt']; break;
            case 0x7C: $code[] = ['i64.add']; break;
            case 0x7D: $code[] = ['i64.sub']; break;
            case 0x7E: $code[] = ['i64.mul']; break;
            case 0x7F: $code[] = ['i64.div_s']; break;
            case 0x80: $code[] = ['i64.div_u']; break;
            case 0x81: $code[] = ['i64.rem_s']; break;
            case 0x82: $code[] = ['i64.rem_u']; break;
            case 0x83: $code[] = ['i64.and']; break;
            case 0x84: $code[] = ['i64.or']; break;
            case 0x85: $code[] = ['i64.xor']; break;
            case 0x86: $code[] = ['i64.shl']; break;
            case 0x87: $code[] = ['i64.shr_s']; break;
            case 0x88: $code[] = ['i64.shr_u']; break;
            case 0x89: $code[] = ['i64.rotl']; break;
            case 0x8A: $code[] = ['i64.rotr']; break;

            // ---- f32 arithmetic ----
            case 0x8B: $code[] = ['f32.abs']; break;
            case 0x8C: $code[] = ['f32.neg']; break;
            case 0x8D: $code[] = ['f32.ceil']; break;
            case 0x8E: $code[] = ['f32.floor']; break;
            case 0x8F: $code[] = ['f32.trunc']; break;
            case 0x90: $code[] = ['f32.nearest']; break;
            case 0x91: $code[] = ['f32.sqrt']; break;
            case 0x92: $code[] = ['f32.add']; break;
            case 0x93: $code[] = ['f32.sub']; break;
            case 0x94: $code[] = ['f32.mul']; break;
            case 0x95: $code[] = ['f32.div']; break;
            case 0x96: $code[] = ['f32.min']; break;
            case 0x97: $code[] = ['f32.max']; break;
            case 0x98: $code[] = ['f32.copysign']; break;

            // ---- f64 arithmetic ----
            case 0x99: $code[] = ['f64.abs']; break;
            case 0x9A: $code[] = ['f64.neg']; break;
            case 0x9B: $code[] = ['f64.ceil']; break;
            case 0x9C: $code[] = ['f64.floor']; break;
            case 0x9D: $code[] = ['f64.trunc']; break;
            case 0x9E: $code[] = ['f64.nearest']; break;
            case 0x9F: $code[] = ['f64.sqrt']; break;
            case 0xA0: $code[] = ['f64.add']; break;
            case 0xA1: $code[] = ['f64.sub']; break;
            case 0xA2: $code[] = ['f64.mul']; break;
            case 0xA3: $code[] = ['f64.div']; break;
            case 0xA4: $code[] = ['f64.min']; break;
            case 0xA5: $code[] = ['f64.max']; break;
            case 0xA6: $code[] = ['f64.copysign']; break;

            // ---- Conversions ----
            case 0xA7: $code[] = ['i32.wrap_i64']; break;
            case 0xA8: $code[] = ['i32.trunc_f32_s']; break;
            case 0xA9: $code[] = ['i32.trunc_f32_u']; break;
            case 0xAA: $code[] = ['i32.trunc_f64_s']; break;
            case 0xAB: $code[] = ['i32.trunc_f64_u']; break;
            case 0xAC: $code[] = ['i64.extend_i32_s']; break;
            case 0xAD: $code[] = ['i64.extend_i32_u']; break;
            case 0xAE: $code[] = ['i64.trunc_f32_s']; break;
            case 0xAF: $code[] = ['i64.trunc_f32_u']; break;
            case 0xB0: $code[] = ['i64.trunc_f64_s']; break;
            case 0xB1: $code[] = ['i64.trunc_f64_u']; break;
            case 0xB2: $code[] = ['f32.convert_i32_s']; break;
            case 0xB3: $code[] = ['f32.convert_i32_u']; break;
            case 0xB4: $code[] = ['f32.convert_i64_s']; break;
            case 0xB5: $code[] = ['f32.convert_i64_u']; break;
            case 0xB6: $code[] = ['f32.demote_f64']; break;
            case 0xB7: $code[] = ['f64.convert_i32_s']; break;
            case 0xB8: $code[] = ['f64.convert_i32_u']; break;
            case 0xB9: $code[] = ['f64.convert_i64_s']; break;
            case 0xBA: $code[] = ['f64.convert_i64_u']; break;
            case 0xBB: $code[] = ['f64.promote_f32']; break;

            // ---- Reinterpret ----
            case 0xBC: $code[] = ['i32.reinterpret_f32']; break;
            case 0xBD: $code[] = ['i64.reinterpret_f64']; break;
            case 0xBE: $code[] = ['f32.reinterpret_i32']; break;
            case 0xBF: $code[] = ['f64.reinterpret_i64']; break;

            // ---- Sign extension ----
            case 0xC0: $code[] = ['i32.extend8_s']; break;
            case 0xC1: $code[] = ['i32.extend16_s']; break;
            case 0xC2: $code[] = ['i64.extend8_s']; break;
            case 0xC3: $code[] = ['i64.extend16_s']; break;
            case 0xC4: $code[] = ['i64.extend32_s']; break;

            // ---- References ----
            case 0xD0: // ref.null
                $this->readHeapType($r); // heap type
                $code[] = ['ref.null'];
                break;
            case 0xD1: $code[] = ['ref.is_null']; break;
            case 0xD2: $code[] = ['ref.func', $r->readU32()]; break;

            // ---- Multi-byte prefix (0xFC) ----
            case 0xFC:
                $this->decodeFCPrefixed($r, $code);
                break;

            default:
                throw new WasmError("unknown opcode: 0x" . dechex($opcode));
        }
    }

    /**
     * Decode 0xFC-prefixed opcodes (saturating truncation, bulk memory, table ops).
     */
    private function decodeFCPrefixed(BinaryReader $r, array &$code): void
    {
        $sub = $r->readU32();
        match ($sub) {
            // Saturating truncation
            0  => $code[] = ['i32.trunc_sat_f32_s'],
            1  => $code[] = ['i32.trunc_sat_f32_u'],
            2  => $code[] = ['i32.trunc_sat_f64_s'],
            3  => $code[] = ['i32.trunc_sat_f64_u'],
            4  => $code[] = ['i64.trunc_sat_f32_s'],
            5  => $code[] = ['i64.trunc_sat_f32_u'],
            6  => $code[] = ['i64.trunc_sat_f64_s'],
            7  => $code[] = ['i64.trunc_sat_f64_u'],

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
        $memIdx = $r->readByte(); // must be 0x00
        $code[] = ['memory.init', $segIdx];
    }

    private function decodeDataDrop(BinaryReader $r, array &$code): void
    {
        $segIdx = $r->readU32();
        $code[] = ['data.drop', $segIdx];
    }

    private function decodeMemoryCopy(BinaryReader $r, array &$code): void
    {
        $r->readByte(); // dst memory (0x00)
        $r->readByte(); // src memory (0x00)
        $code[] = ['memory.copy'];
    }

    private function decodeMemoryFill(BinaryReader $r, array &$code): void
    {
        $r->readByte(); // memory index (0x00)
        $code[] = ['memory.fill'];
    }

    private function decodeTableInit(BinaryReader $r, array &$code): void
    {
        $elemIdx  = $r->readU32();
        $tableIdx = $r->readU32();
        $code[] = ['table.init', $tableIdx, $elemIdx];
    }

    private function decodeElemDrop(BinaryReader $r, array &$code): void
    {
        $elemIdx = $r->readU32();
        $code[] = ['elem.drop', $elemIdx];
    }

    private function decodeTableCopy(BinaryReader $r, array &$code): void
    {
        $dstTable = $r->readU32();
        $srcTable = $r->readU32();
        $code[] = ['table.copy', $dstTable, $srcTable];
    }

    private function decodeTableGrow(BinaryReader $r, array &$code): void
    {
        $tableIdx = $r->readU32();
        $code[] = ['table.grow', $tableIdx];
    }

    private function decodeTableSize(BinaryReader $r, array &$code): void
    {
        $tableIdx = $r->readU32();
        $code[] = ['table.size', $tableIdx];
    }

    private function decodeTableFill(BinaryReader $r, array &$code): void
    {
        $tableIdx = $r->readU32();
        $code[] = ['table.fill', $tableIdx];
    }

    /**
     * Read memory argument (align + offset), return only offset.
     * The Executor only uses offset; alignment is for optimization hints.
     */
    private function readMemArg(BinaryReader $r): int
    {
        $r->readU32(); // align (ignored)
        return $r->readU32(); // offset
    }
}
