<?php

declare(strict_types=1);

namespace WasmRuntime;

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

        for ($ip = 0; $ip < $n; $ip++) {
            $instr = $code[$ip];
            $op    = $instr[0];

            switch ($op) {


                // ---- Unreachable / nop ----
                case 'unreachable':
                    $this->markUnreachable();
                    break;
                case 'nop':
                    break;

                // ---- Block / loop / if / else / end ----
                case 'block': {
                    $bt = $instr[1]; // FuncType|null
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->popTypes($pin);
                    $this->pushCtrl('block', $pout, $pout, $pin);
                    $this->pushTypes($pin);
                    break;
                }
                case 'loop': {
                    $bt = $instr[1];
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->popTypes($pin);
                    $this->pushCtrl('loop', $pin, $pout, $pin);  // label_types = params for loop
                    $this->pushTypes($pin);
                    break;
                }
                case 'if': {
                    $bt      = $instr[1];
                    $elseIp  = $instr[2];
                    $endIp   = $instr[3];
                    [$pin, $pout] = $this->blockTypes($bt);
                    $this->pop(ValType::I32); // condition
                    $this->popTypes($pin);
                    // if without else is only valid when result type is empty
                    if ($elseIp === $endIp && !empty($pout)) {
                        throw new WasmError('type mismatch');
                    }
                    $this->pushCtrl('if', $pout, $pout, $pin);
                    $this->pushTypes($pin);
                    break;
                }
                case 'else': {
                    $frame = $this->popCtrl();
                    if ($frame['opcode'] !== 'if') {
                        throw new WasmError('type mismatch');
                    }
                    // else starts fresh with the if's param types (not result types)
                    $this->pushCtrl('else', $frame['label_types'], $frame['end_types'], $frame['in_types']);
                    $this->pushTypes($frame['in_types']);
                    break;
                }
                case 'end': {
                    $frame = $this->popCtrl();
                    $this->pushTypes($frame['end_types']);
                    break;
                }

                // ---- Branches / return ----
                case 'return': {
                    $ft2 = $this->ctrlStack[0];
                    $this->popTypes($ft2['end_types']);
                    $this->markUnreachable();
                    break;
                }
                case 'br': {
                    $depth = (int)$instr[1];
                    $label = $this->labelAt($depth);
                    $this->popTypes($label['label_types']);
                    $this->markUnreachable();
                    break;
                }
                case 'br_if': {
                    $depth = (int)$instr[1];
                    $label = $this->labelAt($depth);
                    $this->pop(ValType::I32);
                    $this->popTypes($label['label_types']);
                    $this->pushTypes($label['label_types']);
                    break;
                }
                case 'br_table': {
                    $cnt     = count($instr) - 1;
                    $targets = array_slice($instr, 1, $cnt - 1);
                    $default = (int)$instr[$cnt];
                    $this->pop(ValType::I32);
                    $defLabel = $this->labelAt($default);
                    foreach ($targets as $t) {
                        $label = $this->labelAt((int)$t);
                        // All targets must have same arity as default
                        if (count($label['label_types']) !== count($defLabel['label_types'])) {
                            throw new WasmError('type mismatch');
                        }
                    }
                    $this->popTypes($defLabel['label_types']);
                    $this->markUnreachable();
                    break;
                }

                // ---- Call ----
                case 'call': {
                    $cft = $this->mod->funcType((int)$instr[1]);
                    $this->popTypes($cft->params);
                    $this->pushTypes($cft->results);
                    break;
                }
                case 'return_call': {
                    // Tail call: callee's result types must match enclosing function's result types
                    $cft = $this->mod->funcType((int)$instr[1]);
                    $this->popTypes($cft->params);
                    if ($cft->results !== $this->currentFt->results) {
                        throw new WasmError('type mismatch');
                    }
                    $this->markUnreachable();
                    break;
                }
                case 'call_indirect': {
                    $typeIdx  = (int)$instr[1];
                    $tableIdx = (int)($instr[2] ?? 0);
                    $cft      = $this->mod->types[$typeIdx] ?? null;
                    if ($cft === null) throw new WasmError('unknown type');
                    // call_indirect requires a funcref table (not externref)
                    $elemType = $this->tableElemType($tableIdx);
                    if ($elemType !== ValType::FUNCREF) throw new WasmError('type mismatch');
                    $this->pop(ValType::I32); // table index
                    $this->popTypes($cft->params);
                    $this->pushTypes($cft->results);
                    break;
                }
                case 'return_call_indirect': {
                    // Tail indirect call: callee's result types must match enclosing function's result types
                    $typeIdx  = (int)$instr[1];
                    $tableIdx = (int)($instr[2] ?? 0);
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
                case 'drop':
                    $this->pop(null);
                    break;
                case 'select': {
                    $this->pop(ValType::I32);
                    $t2 = $this->pop(null);
                    $t1 = $this->pop(null);
                    // Untyped select is only valid for numeric types (not ref types)
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
                case 'local.get': {
                    $idx = (int)$instr[1];
                    $this->push($this->localType($idx));
                    break;
                }
                case 'local.set': {
                    $idx = (int)$instr[1];
                    $this->pop($this->localType($idx));
                    break;
                }
                case 'local.tee': {
                    $idx = (int)$instr[1];
                    $t = $this->localType($idx);
                    $this->pop($t);
                    $this->push($t);
                    break;
                }

                // ---- Globals ----
                case 'global.get': {
                    $t = $this->globalType((int)$instr[1]);
                    $this->push($t);
                    break;
                }
                case 'global.set': {
                    $t = $this->globalType((int)$instr[1]);
                    $this->pop($t);
                    break;
                }

                // ---- Constants ----
                case 'i32.const': $this->push(ValType::I32); break;
                case 'i64.const': $this->push(ValType::I64); break;
                case 'f32.const': $this->push(ValType::F32); break;
                case 'f64.const': $this->push(ValType::F64); break;

                // ---- i32 arithmetic / comparison ----
                case 'i32.clz': case 'i32.ctz': case 'i32.popcnt':
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'i32.add': case 'i32.sub': case 'i32.mul':
                case 'i32.div_s': case 'i32.div_u': case 'i32.rem_s': case 'i32.rem_u':
                case 'i32.and': case 'i32.or': case 'i32.xor':
                case 'i32.shl': case 'i32.shr_s': case 'i32.shr_u':
                case 'i32.rotl': case 'i32.rotr':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'i32.eqz':
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'i32.eq': case 'i32.ne':
                case 'i32.lt_s': case 'i32.lt_u': case 'i32.gt_s': case 'i32.gt_u':
                case 'i32.le_s': case 'i32.le_u': case 'i32.ge_s': case 'i32.ge_u':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->push(ValType::I32); break;

                // ---- i64 arithmetic / comparison ----
                case 'i64.clz': case 'i64.ctz': case 'i64.popcnt':
                    $this->pop(ValType::I64); $this->push(ValType::I64); break;
                case 'i64.add': case 'i64.sub': case 'i64.mul':
                case 'i64.div_s': case 'i64.div_u': case 'i64.rem_s': case 'i64.rem_u':
                case 'i64.and': case 'i64.or': case 'i64.xor':
                case 'i64.shl': case 'i64.shr_s': case 'i64.shr_u':
                case 'i64.rotl': case 'i64.rotr':
                    $this->pop(ValType::I64); $this->pop(ValType::I64); $this->push(ValType::I64); break;
                case 'i64.eqz':
                    $this->pop(ValType::I64); $this->push(ValType::I32); break;
                case 'i64.eq': case 'i64.ne':
                case 'i64.lt_s': case 'i64.lt_u': case 'i64.gt_s': case 'i64.gt_u':
                case 'i64.le_s': case 'i64.le_u': case 'i64.ge_s': case 'i64.ge_u':
                    $this->pop(ValType::I64); $this->pop(ValType::I64); $this->push(ValType::I32); break;

                // ---- f32 arithmetic / comparison ----
                case 'f32.abs': case 'f32.neg': case 'f32.ceil': case 'f32.floor':
                case 'f32.trunc': case 'f32.nearest': case 'f32.sqrt':
                    $this->pop(ValType::F32); $this->push(ValType::F32); break;
                case 'f32.add': case 'f32.sub': case 'f32.mul': case 'f32.div':
                case 'f32.min': case 'f32.max': case 'f32.copysign':
                    $this->pop(ValType::F32); $this->pop(ValType::F32); $this->push(ValType::F32); break;
                case 'f32.eq': case 'f32.ne':
                case 'f32.lt': case 'f32.gt': case 'f32.le': case 'f32.ge':
                    $this->pop(ValType::F32); $this->pop(ValType::F32); $this->push(ValType::I32); break;

                // ---- f64 arithmetic / comparison ----
                case 'f64.abs': case 'f64.neg': case 'f64.ceil': case 'f64.floor':
                case 'f64.trunc': case 'f64.nearest': case 'f64.sqrt':
                    $this->pop(ValType::F64); $this->push(ValType::F64); break;
                case 'f64.add': case 'f64.sub': case 'f64.mul': case 'f64.div':
                case 'f64.min': case 'f64.max': case 'f64.copysign':
                    $this->pop(ValType::F64); $this->pop(ValType::F64); $this->push(ValType::F64); break;
                case 'f64.eq': case 'f64.ne':
                case 'f64.lt': case 'f64.gt': case 'f64.le': case 'f64.ge':
                    $this->pop(ValType::F64); $this->pop(ValType::F64); $this->push(ValType::I32); break;

                // ---- Conversions ----
                case 'i32.wrap_i64':
                    $this->pop(ValType::I64); $this->push(ValType::I32); break;
                case 'i32.trunc_f32_s': case 'i32.trunc_f32_u': case 'i32.trunc_sat_f32_s': case 'i32.trunc_sat_f32_u':
                    $this->pop(ValType::F32); $this->push(ValType::I32); break;
                case 'i32.trunc_f64_s': case 'i32.trunc_f64_u': case 'i32.trunc_sat_f64_s': case 'i32.trunc_sat_f64_u':
                    $this->pop(ValType::F64); $this->push(ValType::I32); break;
                case 'i64.extend_i32_s': case 'i64.extend_i32_u':
                    $this->pop(ValType::I32); $this->push(ValType::I64); break;
                case 'i64.trunc_f32_s': case 'i64.trunc_f32_u': case 'i64.trunc_sat_f32_s': case 'i64.trunc_sat_f32_u':
                    $this->pop(ValType::F32); $this->push(ValType::I64); break;
                case 'i64.trunc_f64_s': case 'i64.trunc_f64_u': case 'i64.trunc_sat_f64_s': case 'i64.trunc_sat_f64_u':
                    $this->pop(ValType::F64); $this->push(ValType::I64); break;
                case 'f32.convert_i32_s': case 'f32.convert_i32_u':
                    $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case 'f32.convert_i64_s': case 'f32.convert_i64_u':
                    $this->pop(ValType::I64); $this->push(ValType::F32); break;
                case 'f32.demote_f64':
                    $this->pop(ValType::F64); $this->push(ValType::F32); break;
                case 'f64.convert_i32_s': case 'f64.convert_i32_u':
                    $this->pop(ValType::I32); $this->push(ValType::F64); break;
                case 'f64.convert_i64_s': case 'f64.convert_i64_u':
                    $this->pop(ValType::I64); $this->push(ValType::F64); break;
                case 'f64.promote_f32':
                    $this->pop(ValType::F32); $this->push(ValType::F64); break;
                case 'i32.reinterpret_f32':
                    $this->pop(ValType::F32); $this->push(ValType::I32); break;
                case 'i64.reinterpret_f64':
                    $this->pop(ValType::F64); $this->push(ValType::I64); break;
                case 'f32.reinterpret_i32':
                    $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case 'f64.reinterpret_i64':
                    $this->pop(ValType::I64); $this->push(ValType::F64); break;
                case 'i32.extend8_s': case 'i32.extend16_s':
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'i64.extend8_s': case 'i64.extend16_s': case 'i64.extend32_s':
                    $this->pop(ValType::I64); $this->push(ValType::I64); break;

                // ---- Memory ----
                case 'i32.load': case 'i32.load8_s': case 'i32.load8_u':
                case 'i32.load16_s': case 'i32.load16_u':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'i64.load': case 'i64.load8_s': case 'i64.load8_u':
                case 'i64.load16_s': case 'i64.load16_u': case 'i64.load32_s': case 'i64.load32_u':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I32); $this->push(ValType::I64); break;
                case 'f32.load':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I32); $this->push(ValType::F32); break;
                case 'f64.load':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I32); $this->push(ValType::F64); break;
                case 'i32.store': case 'i32.store8': case 'i32.store16':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case 'i64.store': case 'i64.store8': case 'i64.store16': case 'i64.store32':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::I64); $this->pop(ValType::I32); break;
                case 'f32.store':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::F32); $this->pop(ValType::I32); break;
                case 'f64.store':
                    $this->checkMemArg($op, (int)($instr[1] ?? 0), (int)($instr[2] ?? 0));
                    $this->pop(ValType::F64); $this->pop(ValType::I32); break;
                case 'memory.size':
                    $this->push(ValType::I32); break;
                case 'memory.grow':
                    $this->pop(ValType::I32); $this->push(ValType::I32); break;
                case 'memory.copy': case 'memory.fill':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case 'memory.init':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case 'data.drop': break;

                // ---- Table ----
                case 'table.get': {
                    $tIdx = (int)($instr[1] ?? 0);
                    $this->pop(ValType::I32);
                    $this->push($this->tableElemType($tIdx));
                    break;
                }
                case 'table.set': {
                    $tIdx = (int)($instr[1] ?? 0);
                    $this->pop($this->tableElemType($tIdx));
                    $this->pop(ValType::I32);
                    break;
                }
                case 'table.size':
                    $this->push(ValType::I32); break;
                case 'table.grow': {
                    $tIdx = (int)($instr[1] ?? 0);
                    $this->pop(ValType::I32);
                    $this->pop($this->tableElemType($tIdx));
                    $this->push(ValType::I32);
                    break;
                }
                case 'table.fill': {
                    $tIdx = (int)($instr[1] ?? 0);
                    $this->pop(ValType::I32);
                    $this->pop($this->tableElemType($tIdx));
                    $this->pop(ValType::I32);
                    break;
                }
                case 'table.copy':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case 'table.init':
                    $this->pop(ValType::I32); $this->pop(ValType::I32); $this->pop(ValType::I32); break;
                case 'elem.drop': break;

                // ---- Ref types ----
                case 'ref.null': {
                    $heapType = (string)($instr[1] ?? 'func');
                    $refType  = match ($heapType) {
                        'extern', 'externref' => ValType::EXTERNREF,
                        default               => ValType::FUNCREF,
                    };
                    $this->push($refType);
                    break;
                }
                case 'ref.is_null':
                    $this->pop(null); $this->push(ValType::I32); break;
                case 'ref.func':
                    $this->push(ValType::FUNCREF); break;

                // ---- Unknown: skip (don't fail on unknown instructions) ----
                default:
                    break;
            }
        }

        // If the function frame is still open (no explicit 'end'), validate it now
        if (!empty($this->ctrlStack)) {
            $this->popCtrl(); // reuse the same polymorphic-aware check
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

