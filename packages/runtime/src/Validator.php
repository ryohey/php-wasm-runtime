<?php

declare(strict_types=1);

namespace WasmRuntime;

use WasmRuntime\Op;

/**
 * WebAssembly module type validator.
 *
 * Performs the same abstract-interpretation type check that the spec mandates.
 * Throws WasmError("type mismatch") when a function body is ill-typed.
 *
 * Control-frame shape:
 *   ['opcode'      => string,        // 'func'|'block'|'loop'|'if'
 *    'label_types' => int[],         // types for br to this label
 *    'end_types'   => int[],         // types expected at 'end'
 *    'height'      => int,           // typeStack depth at frame entry
 *    'unreachable' => bool]          // dead-code flag
 */
final class Validator
{
    /** @var int[] */
    private array $typeStack = [];
    /** @var array[] */
    private array $ctrlStack = [];

    private Module $mod;
    private FuncType $currentFt;
    private array $currentLocals = [];

    // -------------------------------------------------------------------------

    public function validateModule(Module $mod): void
    {
        $this->mod = $mod;

        // Validate start function
        if ($mod->startFunc >= 0) {
            $total = $mod->importedFuncCount + count($mod->funcBodies);
            if ($mod->startFunc >= $total) {
                throw new WasmError('unknown function');
            }
            $startFt = $mod->funcType($mod->startFunc);
            if (!empty($startFt->params) || !empty($startFt->results)) {
                throw new WasmError('type mismatch');
            }
        }

        // Validate data segments: each active segment must reference a valid memory
        $memCount = $mod->importedMemoryCount + count($mod->memories);
        foreach ($mod->dataSegments as $seg) {
            if (!isset($seg['offset'])) continue; // passive segment, no memory required
            $memIdx = $seg['memIndex'] ?? 0;
            if ($memCount === 0 || $memIdx >= $memCount) {
                throw new WasmError('unknown memory');
            }
        }

        // Validate element segments
        $tableCount  = $mod->importedTableCount + count($mod->tables);
        $funcCount   = $mod->importedFuncCount + count($mod->funcBodies);
        foreach ($mod->elements as $elem) {
            // Active elem segments must reference a valid table
            if (isset($elem['offset'])) {
                $tIdx = $elem['tableIndex'] ?? 0;
                if ($tableCount === 0 || $tIdx >= $tableCount) {
                    throw new WasmError('unknown table');
                }
            }
            // All func indices in elem must be valid
            foreach ($elem['funcIndices'] ?? [] as $fIdx) {
                if ($fIdx >= $funcCount) {
                    throw new WasmError('unknown function');
                }
            }
        }

        // Validate function type indices (local functions)
        $typeCount = count($mod->types);
        foreach ($mod->funcTypeIndices as $typeIdx) {
            if ($typeIdx >= $typeCount) {
                throw new WasmError('unknown type');
            }
        }

        // Validate imported function type indices
        foreach ($mod->imports as $imp) {
            if ($imp['kind'] === 'func') {
                $typeIdx = $imp['typeIndex'] ?? -1;
                if ($typeIdx >= 0 && $typeIdx >= $typeCount) {
                    throw new WasmError('unknown type');
                }
            }
        }

        // Validate export indices
        $globalCount = count(array_filter($mod->imports, fn($i) => $i['kind'] === 'global')) + count($mod->globals);
        foreach ($mod->exports as $name => $exp) {
            $idx = $exp['index'];
            match ($exp['kind']) {
                'func'   => $idx >= $funcCount ? throw new WasmError('unknown function') : null,
                'table'  => $idx >= $tableCount ? throw new WasmError('unknown table') : null,
                'memory' => $idx >= $memCount ? throw new WasmError('unknown memory') : null,
                'global' => $idx >= $globalCount ? throw new WasmError('unknown global') : null,
                default  => null,
            };
        }

        foreach ($mod->funcBodies as $i => $body) {
            $absIdx = $mod->importedFuncCount + $i;
            $ft     = $mod->funcType($absIdx);
            $this->validateFunction($body, $ft);
        }
    }

    // -------------------------------------------------------------------------

    private function validateFunction(array $body, FuncType $ft): void
    {
        $this->typeStack    = [];
        $this->ctrlStack    = [];
        $this->currentFt    = $ft;
        $this->currentLocals = $body['locals'] ?? [];

        // Outer "function" control frame.
        // label_types = results (br 0 inside a function is like returning)
        $this->pushCtrl('func', $ft->results, $ft->results);

        $code = $body['code'];
        $n    = count($code);
        $ip   = 0;

        while ($ip < $n) {
            $op = $code[$ip++];

            switch ($op) {

                // ---- Unreachable / nop ----
                case Op::UNREACHABLE:
                    $this->markUnreachable();
                    break;
                case Op::NOP:
                    break;

                // ---- Block / loop / if / else / end ----
                case Op::BLOCK: {
                    $bt = $code[$ip++]; // FuncType|null
                    $ip++; // endIp
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->popTypes($pin);
                    $this->pushCtrl('block', $pout, $pout, $pin);
                    $this->pushTypes($pin);
                    break;
                }
                case Op::LOOP: {
                    $bt = $code[$ip++];
                    $ip++; // contIp
                    $ip++; // endIp
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->popTypes($pin);
                    $this->pushCtrl('loop', $pin, $pout, $pin);
                    $this->pushTypes($pin);
                    break;
                }
                case Op::IF_: {
                    $bt     = $code[$ip++];
                    $elseIp = $code[$ip++];
                    $endIp  = $code[$ip++];
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->pop(ValType::I32);
                    $this->popTypes($pin);
                    if ($elseIp === $endIp && !empty($pout)) {
                        throw new WasmError('type mismatch');
                    }
                    $this->pushCtrl('if', $pout, $pout, $pin);
                    $this->pushTypes($pin);
                    break;
                }
                case Op::ELSE_: {
                    $ip++; // endIp
                    $frame = $this->popCtrl();
                    if ($frame['opcode'] !== 'if') {
                        throw new WasmError('type mismatch');
                    }
                    $this->pushCtrl('else', $frame['label_types'], $frame['end_types'], $frame['in_types']);
                    $this->pushTypes($frame['in_types']);
                    break;
                }
                case Op::END: {
                    $frame = $this->popCtrl();
                    $this->pushTypes($frame['end_types']);
                    break;
                }

                // ---- Branches / return ----
                case Op::RETURN_: {
                    $ft2 = $this->ctrlStack[0];
                    $this->popTypes($ft2['end_types']);
                    $this->markUnreachable();
                    break;
                }
                case Op::BR: {
                    $depth = $code[$ip++];
                    $label = $this->labelAt($depth);
                    $this->popTypes($label['label_types']);
                    $this->markUnreachable();
                    break;
                }
                case Op::BR_IF: {
                    $depth = $code[$ip++];
                    $label = $this->labelAt($depth);
                    $this->pop(ValType::I32);
                    $this->popTypes($label['label_types']);
                    $this->pushTypes($label['label_types']);
                    break;
                }
                case Op::BR_TABLE: {
                    $cnt = $code[$ip++]; // label count
                    $targets = [];
                    for ($j = 0; $j < $cnt; $j++) {
                        $targets[] = $code[$ip++];
                    }
                    $default = $code[$ip++];
                    $this->pop(ValType::I32);
                    $defLabel = $this->labelAt($default);
                    foreach ($targets as $t) {
                        $label = $this->labelAt((int)$t);
                        if (count($label['label_types']) !== count($defLabel['label_types'])) {
                            throw new WasmError('type mismatch');
                        }
                    }
                    $this->popTypes($defLabel['label_types']);
                    $this->markUnreachable();
                    break;
                }

                // ---- Call ----
                case Op::CALL: {
                    $cft = $this->mod->funcType($code[$ip++]);
                    $this->popTypes($cft->params);
                    $this->pushTypes($cft->results);
                    break;
                }
                case Op::RETURN_CALL: {
                    $cft = $this->mod->funcType($code[$ip++]);
                    $this->popTypes($cft->params);
                    if ($cft->results !== $this->currentFt->results) {
                        throw new WasmError('type mismatch');
                    }
                    $this->markUnreachable();
                    break;
                }
                case Op::CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $cft      = $this->mod->types[$typeIdx] ?? null;
                    if ($cft === null) throw new WasmError('unknown type');
                    $elemType = $this->tableElemType($tableIdx);
                    if ($elemType !== ValType::FUNCREF) throw new WasmError('type mismatch');
                    $this->pop(ValType::I32);
                    $this->popTypes($cft->params);
                    $this->pushTypes($cft->results);
                    break;
                }
                case Op::RETURN_CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $cft      = $this->mod->types[$typeIdx] ?? null;
                    if ($cft === null) throw new WasmError('unknown type');
                    $elemType = $this->tableElemType($tableIdx);
                    if ($elemType !== ValType::FUNCREF) throw new WasmError('type mismatch');
                    $this->pop(ValType::I32);
                    $this->popTypes($cft->params);
                    if ($cft->results !== $this->currentFt->results) {
                        throw new WasmError('type mismatch');
                    }
                    $this->markUnreachable();
                    break;
                }

                // ---- Parametric ----
                case Op::DROP:
                    $this->pop(null);
                    break;
                case Op::SELECT: {
                    $this->pop(ValType::I32);
                    $t2 = $this->pop(null);
                    $t1 = $this->pop(null);
                    $refTypes = [ValType::FUNCREF, ValType::EXTERNREF];
                    if ($t1 !== null && in_array($t1, $refTypes, true)) {
                        throw new WasmError('type mismatch');
                    }
                    if ($t2 !== null && in_array($t2, $refTypes, true)) {
                        throw new WasmError('type mismatch');
                    }
                    if ($t1 !== null && $t2 !== null && $t1 !== $t2) {
                        throw new WasmError('type mismatch');
                    }
                    $this->push($t1 ?? $t2 ?? ValType::I32);
                    break;
                }

                // ---- Locals ----
                case Op::LOCAL_GET:
                    $this->push($this->localType($code[$ip++])); break;
                case Op::LOCAL_SET:
                    $this->pop($this->localType($code[$ip++])); break;
                case Op::LOCAL_TEE: {
                    $idx = $code[$ip++];
                    $t = $this->localType($idx);
                    $this->pop($t);
                    $this->push($t);
                    break;
                }

                // ---- Globals ----
                case Op::GLOBAL_GET:
                    $this->push($this->globalType($code[$ip++])); break;
                case Op::GLOBAL_SET:
                    $this->pop($this->globalType($code[$ip++])); break;

                // ---- Constants ----
                case Op::I32_CONST: $ip++; $this->push(ValType::I32); break;
                case Op::I64_CONST: $ip++; $this->push(ValType::I64); break;
                case Op::F32_CONST: $ip++; $this->push(ValType::F32); break;
                case Op::F64_CONST: $ip++; $this->push(ValType::F64); break;

                // ---- i32 arithmetic / comparison ----
                case Op::I32_CLZ: case Op::I32_CTZ: case Op::I32_POPCNT:
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::I32_ADD: case Op::I32_SUB: case Op::I32_MUL:
                case Op::I32_DIV_S: case Op::I32_DIV_U: case Op::I32_REM_S: case Op::I32_REM_U:
                case Op::I32_AND: case Op::I32_OR: case Op::I32_XOR:
                case Op::I32_SHL: case Op::I32_SHR_S: case Op::I32_SHR_U:
                case Op::I32_ROTL: case Op::I32_ROTR:
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::I32_EQZ:
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::I32_EQ: case Op::I32_NE:
                case Op::I32_LT_S: case Op::I32_LT_U: case Op::I32_GT_S: case Op::I32_GT_U:
                case Op::I32_LE_S: case Op::I32_LE_U: case Op::I32_GE_S: case Op::I32_GE_U:
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->push(ValType::I32); break;

                // ---- i64 arithmetic / comparison ----
                case Op::I64_CLZ: case Op::I64_CTZ: case Op::I64_POPCNT:
                    $this->pop(ValType::I64); $this->push(ValType::I64); break;
                case Op::I64_ADD: case Op::I64_SUB: case Op::I64_MUL:
                case Op::I64_DIV_S: case Op::I64_DIV_U: case Op::I64_REM_S: case Op::I64_REM_U:
                case Op::I64_AND: case Op::I64_OR: case Op::I64_XOR:
                case Op::I64_SHL: case Op::I64_SHR_S: case Op::I64_SHR_U:
                case Op::I64_ROTL: case Op::I64_ROTR:
                    $this->pop(ValType::I64); $this->pop(ValType::I64); $this->push(ValType::I64); break;
                case Op::I64_EQZ:
                    $this->pop(ValType::I64); $this->push(ValType::I32); break;
                case Op::I64_EQ: case Op::I64_NE:
                case Op::I64_LT_S: case Op::I64_LT_U: case Op::I64_GT_S: case Op::I64_GT_U:
                case Op::I64_LE_S: case Op::I64_LE_U: case Op::I64_GE_S: case Op::I64_GE_U:
                    $this->pop(ValType::I64); $this->pop(ValType::I64); $this->push(ValType::I32); break;

                // ---- f32 arithmetic / comparison ----
                case Op::F32_ABS: case Op::F32_NEG: case Op::F32_CEIL: case Op::F32_FLOOR:
                case Op::F32_TRUNC: case Op::F32_NEAREST: case Op::F32_SQRT:
                    $this->pop(ValType::F32); $this->push(ValType::F32); break;
                case Op::F32_ADD: case Op::F32_SUB: case Op::F32_MUL: case Op::F32_DIV:
                case Op::F32_MIN: case Op::F32_MAX: case Op::F32_COPYSIGN:
                    $this->pop(ValType::F32); $this->pop(ValType::F32); $this->push(ValType::F32); break;
                case Op::F32_EQ: case Op::F32_NE:
                case Op::F32_LT: case Op::F32_GT: case Op::F32_LE: case Op::F32_GE:
                    $this->pop(ValType::F32); $this->pop(ValType::F32); $this->push(ValType::I32); break;

                // ---- f64 arithmetic / comparison ----
                case Op::F64_ABS: case Op::F64_NEG: case Op::F64_CEIL: case Op::F64_FLOOR:
                case Op::F64_TRUNC: case Op::F64_NEAREST: case Op::F64_SQRT:
                    $this->pop(ValType::F64); $this->push(ValType::F64); break;
                case Op::F64_ADD: case Op::F64_SUB: case Op::F64_MUL: case Op::F64_DIV:
                case Op::F64_MIN: case Op::F64_MAX: case Op::F64_COPYSIGN:
                    $this->pop(ValType::F64); $this->pop(ValType::F64); $this->push(ValType::F64); break;
                case Op::F64_EQ: case Op::F64_NE:
                case Op::F64_LT: case Op::F64_GT: case Op::F64_LE: case Op::F64_GE:
                    $this->pop(ValType::F64); $this->pop(ValType::F64); $this->push(ValType::I32); break;

                // ---- Conversions ----
                case Op::I32_WRAP_I64:
                    $this->pop(ValType::I64); $this->push(ValType::I32); break;
                case Op::I32_TRUNC_F32_S: case Op::I32_TRUNC_F32_U: case Op::I32_TRUNC_SAT_F32_S: case Op::I32_TRUNC_SAT_F32_U:
                    $this->pop(ValType::F32); $this->push(ValType::I32); break;
                case Op::I32_TRUNC_F64_S: case Op::I32_TRUNC_F64_U: case Op::I32_TRUNC_SAT_F64_S: case Op::I32_TRUNC_SAT_F64_U:
                    $this->pop(ValType::F64); $this->push(ValType::I32); break;
                case Op::I64_EXTEND_I32_S: case Op::I64_EXTEND_I32_U:
                    $this->pop(ValType::I32); $this->push(ValType::I64); break;
                case Op::I64_TRUNC_F32_S: case Op::I64_TRUNC_F32_U: case Op::I64_TRUNC_SAT_F32_S: case Op::I64_TRUNC_SAT_F32_U:
                    $this->pop(ValType::F32); $this->push(ValType::I64); break;
                case Op::I64_TRUNC_F64_S: case Op::I64_TRUNC_F64_U: case Op::I64_TRUNC_SAT_F64_S: case Op::I64_TRUNC_SAT_F64_U:
                    $this->pop(ValType::F64); $this->push(ValType::I64); break;
                case Op::F32_CONVERT_I32_S: case Op::F32_CONVERT_I32_U:
                    $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case Op::F32_CONVERT_I64_S: case Op::F32_CONVERT_I64_U:
                    $this->pop(ValType::I64); $this->push(ValType::F32); break;
                case Op::F32_DEMOTE_F64:
                    $this->pop(ValType::F64); $this->push(ValType::F32); break;
                case Op::F64_CONVERT_I32_S: case Op::F64_CONVERT_I32_U:
                    $this->pop(ValType::I32); $this->push(ValType::F64); break;
                case Op::F64_CONVERT_I64_S: case Op::F64_CONVERT_I64_U:
                    $this->pop(ValType::I64); $this->push(ValType::F64); break;
                case Op::F64_PROMOTE_F32:
                    $this->pop(ValType::F32); $this->push(ValType::F64); break;
                case Op::I32_REINTERPRET_F32:
                    $this->pop(ValType::F32); $this->push(ValType::I32); break;
                case Op::I64_REINTERPRET_F64:
                    $this->pop(ValType::F64); $this->push(ValType::I64); break;
                case Op::F32_REINTERPRET_I32:
                    $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case Op::F64_REINTERPRET_I64:
                    $this->pop(ValType::I64); $this->push(ValType::F64); break;
                case Op::I32_EXTEND8_S: case Op::I32_EXTEND16_S:
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::I64_EXTEND8_S: case Op::I64_EXTEND16_S: case Op::I64_EXTEND32_S:
                    $this->pop(ValType::I64); $this->push(ValType::I64); break;

                // ---- Memory ----
                case Op::I32_LOAD: case Op::I32_LOAD8_S: case Op::I32_LOAD8_U:
                case Op::I32_LOAD16_S: case Op::I32_LOAD16_U:
                    $ip++; // skip offset
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::I64_LOAD: case Op::I64_LOAD8_S: case Op::I64_LOAD8_U:
                case Op::I64_LOAD16_S: case Op::I64_LOAD16_U: case Op::I64_LOAD32_S: case Op::I64_LOAD32_U:
                    $ip++; // skip offset
                    $this->pop(ValType::I32); $this->push(ValType::I64); break;
                case Op::F32_LOAD:
                    $ip++; $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case Op::F64_LOAD:
                    $ip++; $this->pop(ValType::I32); $this->push(ValType::F64); break;
                case Op::I32_STORE: case Op::I32_STORE8: case Op::I32_STORE16:
                    $ip++; $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case Op::I64_STORE: case Op::I64_STORE8: case Op::I64_STORE16: case Op::I64_STORE32:
                    $ip++; $this->pop(ValType::I64); $this->pop(ValType::I32); break;
                case Op::F32_STORE:
                    $ip++; $this->pop(ValType::F32); $this->pop(ValType::I32); break;
                case Op::F64_STORE:
                    $ip++; $this->pop(ValType::F64); $this->pop(ValType::I32); break;
                case Op::MEMORY_SIZE:
                    $this->push(ValType::I32); break;
                case Op::MEMORY_GROW:
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case Op::MEMORY_COPY: case Op::MEMORY_FILL:
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case Op::MEMORY_INIT:
                    $ip++; // skip segIdx
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case Op::DATA_DROP: $ip++; break;

                // ---- Table ----
                case Op::TABLE_GET: {
                    $tIdx = $code[$ip++];
                    $this->pop(ValType::I32);
                    $this->push($this->tableElemType($tIdx));
                    break;
                }
                case Op::TABLE_SET: {
                    $tIdx = $code[$ip++];
                    $this->pop($this->tableElemType($tIdx));
                    $this->pop(ValType::I32);
                    break;
                }
                case Op::TABLE_SIZE:
                    $ip++; $this->push(ValType::I32); break;
                case Op::TABLE_GROW: {
                    $tIdx = $code[$ip++];
                    $this->pop(ValType::I32);
                    $this->pop($this->tableElemType($tIdx));
                    $this->push(ValType::I32);
                    break;
                }
                case Op::TABLE_FILL: {
                    $tIdx = $code[$ip++];
                    $this->pop(ValType::I32);
                    $this->pop($this->tableElemType($tIdx));
                    $this->pop(ValType::I32);
                    break;
                }
                case Op::TABLE_COPY:
                    $ip += 2; // dstTable, srcTable
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case Op::TABLE_INIT:
                    $ip += 2; // tableIdx, elemIdx
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case Op::ELEM_DROP: $ip++; break;

                // ---- Ref types ----
                case Op::REF_NULL:
                    $this->push(ValType::FUNCREF); break;
                case Op::REF_IS_NULL:
                    $this->pop(null); $this->push(ValType::I32); break;
                case Op::REF_FUNC:
                    $ip++; $this->push(ValType::FUNCREF); break;
                case Op::REF_AS_NON_NULL:
                    $this->pop(null); $this->push(ValType::FUNCREF); break;

                default:
                    break;
            }
        }

        // If the function frame is still open (no explicit 'end'), validate it now
        if (!empty($this->ctrlStack)) {
            $this->popCtrl();
        }
    }

    // =========================================================================
    // Control stack helpers
    // =========================================================================

    private function pushCtrl(string $opcode, array $labelTypes, array $endTypes, array $inTypes = []): void
    {
        $this->ctrlStack[] = [
            'opcode'      => $opcode,
            'label_types' => $labelTypes,
            'end_types'   => $endTypes,
            'in_types'    => $inTypes,   // param types (for else restoration)
            'height'      => count($this->typeStack),
            'unreachable' => false,
        ];
    }

    private function popCtrl(): array
    {
        if (empty($this->ctrlStack)) {
            throw new WasmError('type mismatch');
        }
        $frame = $this->ctrlStack[count($this->ctrlStack) - 1];

        // Pop end_types from the stack (polymorphic-aware).
        // In polymorphic mode, underflows produce ⊥ (any-match) without actual pop.
        $expected = $frame['end_types'];
        for ($i = count($expected) - 1; $i >= 0; $i--) {
            if (count($this->typeStack) <= $frame['height']) {
                if (!$frame['unreachable']) {
                    throw new WasmError('type mismatch');
                }
                // Polymorphic underflow: treat as ⊥, skip actual pop
            } else {
                $actual = array_pop($this->typeStack);
                if ($actual !== $expected[$i]) {
                    throw new WasmError('type mismatch');
                }
            }
        }

        // After popping end_types, stack must be exactly at frame height.
        // This applies even in unreachable mode — extra unconsumed values are invalid.
        if (count($this->typeStack) !== $frame['height']) {
            throw new WasmError('type mismatch');
        }

        array_pop($this->ctrlStack);
        return $frame;
    }

    private function markUnreachable(): void
    {
        if (empty($this->ctrlStack)) return;
        $idx = count($this->ctrlStack) - 1;
        $this->ctrlStack[$idx]['unreachable'] = true;
        // Truncate stack to frame height
        $this->typeStack = array_slice($this->typeStack, 0, $this->ctrlStack[$idx]['height']);
    }

    /** @return ?array */
    private function labelAt(int $depth): array
    {
        $idx = count($this->ctrlStack) - 1 - $depth;
        if ($idx < 0) throw new WasmError('type mismatch');
        return $this->ctrlStack[$idx];
    }

    // =========================================================================
    // Type-stack helpers
    // =========================================================================

    /**
     * Pop one value; if $expected !== null, check it matches.
     * In unreachable (polymorphic) mode, underflows are allowed (return expected/⊥).
     * But if there IS a concrete type on the stack, still check it.
     */
    private function pop(?int $expected): ?int
    {
        if (empty($this->ctrlStack)) return $expected;
        $frame = &$this->ctrlStack[count($this->ctrlStack) - 1];

        if (count($this->typeStack) <= $frame['height']) {
            // Stack underflow
            if ($frame['unreachable']) {
                return $expected; // polymorphic: ⊥ matches anything
            }
            throw new WasmError('type mismatch');
        }

        $actual = array_pop($this->typeStack);
        if ($expected !== null && $actual !== $expected) {
            throw new WasmError('type mismatch');
        }
        return $actual;
    }

    private function push(int $t): void
    {
        if (empty($this->ctrlStack)) return;
        // Always push, even in unreachable mode — concrete types may be checked by later pops
        $this->typeStack[] = $t;
    }

    /** @param int[] $types */
    private function popTypes(array $types): void
    {
        // Pop in reverse order
        for ($i = count($types) - 1; $i >= 0; $i--) {
            $this->pop($types[$i]);
        }
    }

    /** @param int[] $types */
    private function pushTypes(array $types): void
    {
        foreach ($types as $t) {
            $this->push($t);
        }
    }

    // =========================================================================
    // Utility
    // =========================================================================

    /** @return [int[], int[]] params, results for a block type */
    private function blockTypes(?FuncType $bt): array
    {
        if ($bt === null) {
            return [[], []];
        }
        return [$bt->params, $bt->results];
    }

    private function localType(int $idx): int
    {
        $params = $this->currentFt->params;
        if ($idx < count($params)) {
            return $params[$idx];
        }
        $localIdx = $idx - count($params);
        if (!isset($this->currentLocals[$localIdx])) {
            throw new WasmError('type mismatch'); // unknown local index
        }
        return $this->currentLocals[$localIdx];
    }

    /** Natural alignment (bytes) for each memory instruction */
    private const NATURAL_ALIGN = [
        'i32.load8_s' => 1,  'i32.load8_u' => 1,
        'i32.load16_s' => 2, 'i32.load16_u' => 2,
        'i32.load' => 4,     'f32.load' => 4,     'f32.store' => 4,
        'i32.store8' => 1,   'i32.store16' => 2,  'i32.store' => 4,
        'i64.load8_s' => 1,  'i64.load8_u' => 1,
        'i64.load16_s' => 2, 'i64.load16_u' => 2,
        'i64.load32_s' => 4, 'i64.load32_u' => 4,
        'i64.load' => 8,     'f64.load' => 8,
        'i64.store8' => 1,   'i64.store16' => 2,  'i64.store32' => 4,
        'i64.store' => 8,    'f64.store' => 8,
    ];

    /** Validate memarg: check alignment first, then offset range. */
    private function checkMemArg(string $op, int $offset, int $align): void
    {
        $this->checkAlignment($op, $align);
        // -1 is sentinel stored by parseMemArg when offset > 0xFFFFFFFF
        if ($offset === -1) {
            throw new WasmError('offset out of range');
        }
    }

    private function checkAlignment(string $op, int $align): void
    {
        if ($align === 0) return; // default (natural) — always valid
        $natural = self::NATURAL_ALIGN[$op] ?? PHP_INT_MAX;
        // -1 is sentinel for "overflow from too large alignment value"
        if ($align < 0 || $align > $natural) {
            throw new WasmError('alignment must not be larger than natural');
        }
    }

    private function tableElemType(int $tIdx): int
    {
        $importedCount = 0;
        $tableImports  = [];
        foreach ($this->mod->imports as $imp) {
            if ($imp['kind'] === 'table') {
                $tableImports[] = $imp;
                $importedCount++;
            }
        }
        if ($tIdx < $importedCount) {
            $refType = $tableImports[$tIdx]['refType'] ?? 'funcref';
            return is_int($refType) ? $refType : ValType::fromString((string)$refType);
        }
        $localIdx = $tIdx - $importedCount;
        if ($localIdx >= count($this->mod->tables)) {
            throw new WasmError('unknown table');
        }
        $refType = $this->mod->tables[$localIdx]['type'] ?? 'funcref';
        return is_int($refType) ? $refType : ValType::fromString((string)$refType);
    }

    private function globalType(int $idx): int
    {
        // Imported globals come first
        $imported = 0;
        foreach ($this->mod->imports as $imp) {
            if ($imp['kind'] === 'global') {
                if ($idx === $imported) {
                    return $imp['globalType'] ?? ValType::I32;
                }
                $imported++;
            }
        }
        $localIdx = $idx - $imported;
        if (!isset($this->mod->globals[$localIdx])) {
            throw new WasmError('type mismatch'); // unknown global index
        }
        return $this->mod->globals[$localIdx]['type'];
    }

}

