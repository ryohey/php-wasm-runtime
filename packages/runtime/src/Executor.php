<?php

declare(strict_types=1);

namespace WasmRuntime;

/**
 * Iterative WebAssembly interpreter with flat bytecode dispatch.
 *
 * Instructions are stored as a flat array of integers/values produced by Binary\Decoder:
 *   Op::XXX, immediate1, immediate2, ...
 *
 * The executor reads opcodes via $code[$ip++] and immediates the same way.
 * Stack is managed via a $sp pointer into a pre-allocated array.
 *
 * Branch targets and stack adjustments (spDelta) are precomputed by the Decoder,
 * so BLOCK/LOOP opcodes are never emitted and no runtime label stack is needed.
 * IF/ELSE still use inline elseIp/endIp immediates for conditional dispatch.
 */
final class Executor
{
    private const MAX_CALL_DEPTH = 1000;

    /** @var callable[] absIndex => PHP callable for host functions */
    private array $hostFuncs = [];
    /** @var \Closure[] absIndex => raw host function handlers */
    private array $rawHostFuncs = [];
    private int   $callDepth = 0;

    public function __construct(private readonly Instance $instance) {}

    public function registerHostFunc(int $funcIdx, mixed $fn): void
    {
        if ($fn instanceof RawHostFunc) {
            $this->rawHostFuncs[$funcIdx] = $fn->handler;
            return;
        }
        $this->hostFuncs[$funcIdx] = $fn;
    }

    /**
     * External API: call a function by index with WasmValue args, returns WasmValue[].
     * @param  WasmValue[] $args
     * @return WasmValue[]
     */
    public function invoke(int $funcIdx, array $args): array
    {
        // Unwrap WasmValue args to raw values
        $rawArgs = [];
        foreach ($args as $a) {
            $rawArgs[] = ($a->type === ValType::FUNCREF || $a->type === ValType::EXTERNREF) && $a->value === -1
                ? null : $a->value;
        }

        $rawResults = $this->callFunctionRaw($funcIdx, $rawArgs);

        // Wrap raw results back into WasmValue[]
        $ft  = $this->instance->module->funcTypeFlat[$funcIdx];
        $out = [];
        foreach ($ft->results as $i => $rtype) {
            $v     = array_key_exists($i, $rawResults) ? $rawResults[$i] : 0;
            $out[] = match ($rtype) {
                ValType::I32 => WasmValue::i32((int)$v),
                ValType::I64 => WasmValue::i64((int)$v),
                ValType::F32 => WasmValue::f32(self::asF32($v)),
                ValType::F64 => WasmValue::f64((float)$v),
                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF, $v === null ? -1 : (int)$v),
                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $v === null ? -1 : (int)$v),
                default      => WasmValue::i32((int)$v),
            };
        }
        return $out;
    }

    /**
     * Internal call path: accepts and returns raw (int|float|null) values.
     * Avoids WasmValue object creation for WASM-to-WASM calls.
     * @return (int|float|null)[]
     */
    private function callFunctionRaw(int $funcIdx, array $rawArgs): array
    {
        if (++$this->callDepth > self::MAX_CALL_DEPTH) {
            $this->callDepth--;
            throw Trap::callStackExhausted();
        }
        try {
            if (isset($this->rawHostFuncs[$funcIdx])) {
                $r = ($this->rawHostFuncs[$funcIdx])($rawArgs, 0, count($rawArgs));
                if ($r === null) {
                    return [];
                }
                return is_array($r) ? $r : [$r];
            }
            if (isset($this->hostFuncs[$funcIdx])) {
                $ft    = $this->instance->module->funcTypeFlat[$funcIdx];
                $wargs = [];
                foreach ($rawArgs as $i => $raw) {
                    $type    = $ft->params[$i] ?? ValType::I32;
                    $wargs[] = match ($type) {
                        ValType::FUNCREF   => new WasmValue(ValType::FUNCREF,   $raw === null ? -1 : (int)$raw),
                        ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                        ValType::I64 => WasmValue::i64((int)$raw),
                        ValType::F32 => WasmValue::f32((float)$raw),
                        ValType::F64 => WasmValue::f64((float)$raw),
                        default      => WasmValue::i32((int)($raw ?? 0)),
                    };
                }
                $r       = ($this->hostFuncs[$funcIdx])($wargs);
                $wresult = is_array($r) ? $r : ($r !== null ? [$r] : []);
                $out     = [];
                foreach ($wresult as $rv) {
                    $out[] = ($rv instanceof WasmValue)
                        ? ((($rv->type === ValType::FUNCREF || $rv->type === ValType::EXTERNREF) && $rv->value === -1) ? null : $rv->value)
                        : $rv;
                }
                return $out;
            }
            $mod  = $this->instance->module;
            $body = $mod->funcBodiesFlat[$funcIdx] ?? throw new Trap("Invalid function index: $funcIdx");
            return $this->run($body['code'], $rawArgs, $body['localDefaults'], $mod->resultCounts[$funcIdx]);
        } finally {
            $this->callDepth--;
        }
    }

    /**
     * Main interpreter loop — flat bytecode dispatch.
     *
     * Locals live on the value stack at [$lbase .. $lbase + totalLocals).
     * LOCAL_GET/SET/TEE use $stack[$lbase + localIdx].
     * This eliminates array_slice + array_merge per WASM function call.
     *
     * @param  (int|float|null)[] $rawArgs    argument values
     * @param  (int|float|null)[] $localDefaults  default values for non-arg locals
     * @return (int|float)[]  raw result values
     */
    private function run(array $code, array $rawArgs, array $localDefaults, int $retCount): array
    {
        // Build initial stack: args followed by local defaults
        $stack = $rawArgs;
        $sp    = count($rawArgs);
        foreach ($localDefaults as $v) $stack[$sp++] = $v;
        $lbase = 0;  // index of local[0] in $stack (grows with each CALL frame)
        $ip         = 0;
        $len        = count($code);
        $mem0       = $this->instance->memories[0] ?? null;
        // Cache memory buffer reference and limit for faster inline access.
        // $bytes is a reference to $mem0->bytes so writes are reflected back.
        // $blimit mirrors $mem0->limit; update after MEMORY_GROW.
        $bytes  = null; $blimit = 0; $balloc = 0;
        if ($mem0 !== null) { $bytes = &$mem0->bytes; $blimit = $mem0->limit; $balloc = $mem0->allocated; if ($balloc < $blimit) { $bytes .= str_repeat("\0", $blimit - $balloc); $balloc = $blimit; $mem0->allocated = $balloc; } }
        // Precomputed 256-char string for byte writes — avoids chr() function calls in STORE ops.
        $chrStr = ''; for ($__i=0; $__i<256; $__i++) $chrStr.=chr($__i);
        $mod        = $this->instance->module;
        // Cache hot module arrays as locals — local var access is faster than property dereference.
        $funcCode        = $mod->funcCode;
        $funcCodeLen     = $mod->funcCodeLen;
        $funcLD          = $mod->funcLocalDefaults; // int = zero-fill count, array = actual defaults
        $resultCounts    = $mod->resultCounts;
        $funcAll         = $mod->funcAll; // [code, codeLen, retCount, localDefaults] per WASM function
        $paramCounts     = $mod->paramCounts;
        $typeParamCounts  = $mod->typeParamCounts;
        $funcTypeFlat    = $mod->funcTypeFlat;
        $funcTypeIdxFlat = $mod->funcTypeIndicesFlat;
        $modTypes   = $mod->types;
        $globals    = &$this->instance->globals; // reference to avoid repeated property chain lookup
        $tables     = &$this->instance->tables;
        $rawHostFuncs = $this->rawHostFuncs;
        $hostFuncs  = $this->hostFuncs;
        $retBase = -1; // -1 = normal exit, >=0 = index in $stack where results begin (early return)
        // Iterative call stack: flat array, 6 slots per frame: [code, ip, len, retCount, lbase, newLbase]
        // newLbase = $sp position for callee's local[0] (= start of return-value area on caller's side).
        // $fsp = next free slot index. Push: write fsp[0..5], fsp+=6. Pop: fsp-=6.
        $frameData = [];
        $fsp = 0;

        while (true) { // outer: frame manager — loops once per function frame
        while ($ip < $len) {
            $op = $code[$ip++];

            switch ($op) {

                // ---- Control ----
                case Op::UNREACHABLE:
                    throw Trap::unreachable();

                case Op::NOP:
                    break;

                // BLOCK and LOOP are never emitted by the new decoder — dead cases removed.

                case Op::IF_: {
                    // Reads: [falseTargetIp] — single imm; falseTargetIp is else body start or past block.
                    // No elseIp/endIp comparison needed — falseTargetIp encodes both cases.
                    if ((int)$stack[--$sp] === 0) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_IF_: {
                    // Reads: [localIdx, falseTargetIp] — local.get+if fused; no stack push/pop.
                    $__lIdx = $code[$ip++];
                    if ((int)$stack[$lbase + $__lIdx] === 0) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_I32EQZ_IF_: {
                    // Reads: [falseTargetIp] — i32.eqz+if fused: branch to falseTarget when TOS != 0.
                    if ((int)$stack[--$sp] !== 0) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_I32EQZ_IF_: {
                    // Reads: [localIdx, falseTargetIp] — local.get+i32.eqz+if fused.
                    // Branch to falseTarget when local[idx] != 0 (eqz makes non-zero→0, if branches on 0).
                    $__lIdx = $code[$ip++];
                    if ((int)$stack[$lbase + $__lIdx] !== 0) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_ICONST_I32EQ_IF_: {
                    // Reads: [x, c, falseTargetIp] — if (local[x] == c) enter body else jump to falseTargetIp.
                    $__x = $code[$ip++]; $__c = $code[$ip++];
                    if ((int)$stack[$lbase + $__x] !== $__c) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_ICONST_I32NE_IF_: {
                    // Reads: [x, c, falseTargetIp] — if (local[x] != c) enter body else jump to falseTargetIp.
                    $__x = $code[$ip++]; $__c = $code[$ip++];
                    if ((int)$stack[$lbase + $__x] === $__c) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_LGET_I32EQ_IF_: {
                    // Reads: [a, b, falseTargetIp] — if (local[a] == local[b]) enter body else jump to falseTargetIp.
                    $__a = $code[$ip++]; $__b = $code[$ip++];
                    if ((int)$stack[$lbase + $__a] !== (int)$stack[$lbase + $__b]) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_ICONST_I32LTS_IF_: {
                    // Reads: [x, c, falseTargetIp] — if (local[x] < c) enter body else jump to falseTargetIp.
                    $__x = $code[$ip++]; $__c = $code[$ip++];
                    if ((int)$stack[$lbase + $__x] >= $__c) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_ICONST_I32GTS_IF_: {
                    // Reads: [x, c, falseTargetIp] — if (local[x] > c) enter body else jump to falseTargetIp.
                    $__x = $code[$ip++]; $__c = $code[$ip++];
                    if ((int)$stack[$lbase + $__x] <= $__c) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::SB_LGET_LGET_I32NE_IF_: {
                    // Reads: [a, b, falseTargetIp] — if (local[a] != local[b]) enter body else jump to falseTargetIp.
                    $__a = $code[$ip++]; $__b = $code[$ip++];
                    if ((int)$stack[$lbase + $__a] === (int)$stack[$lbase + $__b]) { $ip = $code[$ip]; } else { $ip++; }
                    break;
                }

                case Op::ELSE_: {
                    $ip = $code[$ip]; // endIp (points past else body, no Op::END)
                    break;
                }

                case Op::END: break; // dead — never emitted by decoder; kept for safety

                case Op::RETURN_: {
                    $retBase = $retCount > 0 ? max(0, $sp - $retCount) : $sp;
                    break 2;
                }

                // ---- Precomputed branches (no label stack) ----
                case Op::SB_BR_PRECOMP: {
                    // Unconditional branch: [targetIp, spDelta, rCnt]
                    $targetIp = $code[$ip++]; $spDelta = $code[$ip++]; $rCnt = $code[$ip++];
                    if ($rCnt > 0 && $spDelta !== 0) { $srcBase=$sp-$rCnt; $dstBase=$srcBase+$spDelta; for($__i=0;$__i<$rCnt;$__i++) $stack[$dstBase+$__i]=$stack[$srcBase+$__i]; }
                    $sp += $spDelta; $ip = $targetIp;
                    break;
                }

                case Op::SB_BRIF_PRECOMP: {
                    // Conditional branch: [targetIp, spDelta, rCnt]
                    $targetIp = $code[$ip++]; $spDelta = $code[$ip++]; $rCnt = $code[$ip++];
                    $cond = (int)$stack[--$sp];
                    if ($cond !== 0) {
                        if ($rCnt > 0 && $spDelta !== 0) { $srcBase=$sp-$rCnt; $dstBase=$srcBase+$spDelta; for($__i=0;$__i<$rCnt;$__i++) $stack[$dstBase+$__i]=$stack[$srcBase+$__i]; }
                        $sp += $spDelta; $ip = $targetIp;
                    }
                    break;
                }

                case Op::SB_BRIF_PRECOMP_ESC: {
                    // Conditional branch escaping function frame → return
                    if ((int)$stack[--$sp] !== 0) {
                        $retBase = $retCount > 0 ? max(0, $sp - $retCount) : $sp;
                        break 2;
                    }
                    break;
                }

                case Op::BR_TABLE: {
                    // Format: [cnt, rCnt, (targetIp, spDelta)*(cnt+1)]
                    // br_table always branches — $ip += skip is dead (overwritten by targetIp or break 2)
                    $cnt  = $code[$ip++]; $rCnt = $code[$ip++];
                    $idx  = (int)$stack[--$sp];
                    $base = ($idx >= 0 && $idx < $cnt) ? $ip + $idx * 2 : $ip + $cnt * 2;
                    $targetIp = $code[$base]; $spDelta = $code[$base + 1];
                    if ($targetIp === -1) { $retBase = ($retCount > 0 && $sp >= $retCount) ? $sp - $retCount : $sp; break 2; }
                    if ($rCnt > 0 && $spDelta !== 0) { $srcBase=$sp-$rCnt; $dstBase=$srcBase+$spDelta; for($__i=0;$__i<$rCnt;$__i++) $stack[$dstBase+$__i]=$stack[$srcBase+$__i]; }
                    $sp += $spDelta; $ip = $targetIp;
                    break;
                }

                case Op::SB_BR_TABLE_VOID: {
                    // Format: [cnt, (targetIp, spDelta)*(cnt+1)] — all targets have 0 results
                    // br_table always branches — $ip += skip is dead (overwritten by targetIp or break 2)
                    $cnt = $code[$ip++];
                    $idx = (int)$stack[--$sp];
                    $base = ($idx >= 0 && $idx < $cnt) ? $ip + $idx * 2 : $ip + $cnt * 2;
                    $targetIp = $code[$base]; $spDelta = $code[$base + 1];
                    if ($targetIp === -1) { $retBase = ($retCount > 0 && $sp >= $retCount) ? $sp - $retCount : $sp; break 2; }
                    $sp += $spDelta; $ip = $targetIp;
                    break;
                }

                case Op::CALL: {
                    $fIdx = $code[$ip++];
                    $pc   = $paramCounts[$fIdx];
                    // Check WASM-to-WASM first (most common): funcAll exists only for non-imported funcs
                    $_fa = $funcAll[$fIdx] ?? null;
                    if ($_fa !== null) {
                        // Iterative WASM-to-WASM call — push frame, switch code
                        if ($fsp >= self::MAX_CALL_DEPTH * 6) throw Trap::callStackExhausted();
                        $newLbase = $sp - $pc;
                        { $_ld=$_fa[3]; if(is_int($_ld)){$_e=$sp+$_ld;while($sp<$_e)$stack[$sp++]=0;}else{foreach($_ld as $_v)$stack[$sp++]=$_v;} }
                        $frameData[$fsp]=$code; $frameData[$fsp+1]=$ip; $frameData[$fsp+2]=$len; $frameData[$fsp+3]=$retCount;
                        $frameData[$fsp+4]=$lbase; $frameData[$fsp+5]=$newLbase;
                        $fsp += 6;
                        $code = $_fa[0]; $lbase = $newLbase;
                        $ip = 0; $len = $_fa[1]; $retCount = $_fa[2];
                        $retBase = -1;
                        break;
                    }
                    if (isset($rawHostFuncs[$fIdx])) {
                        $sp -= $pc;
                        $r = ($rawHostFuncs[$fIdx])($stack, $sp, $pc);
                        if (is_array($r)) {
                            foreach ($r as $rv) {
                                $stack[$sp++] = $rv;
                            }
                        } elseif ($r !== null) {
                            $stack[$sp++] = $r;
                        }
                        break;
                    }
                    if (isset($hostFuncs[$fIdx])) {
                        // Inline host call — no PHP recursion needed
                        $hostFt = $funcTypeFlat[$fIdx];
                        $wargs  = [];
                        $__base = $sp - $pc;
                        for ($__i = 0; $__i < $pc; $__i++) {
                            $raw  = $stack[$__base + $__i];
                            $type = $hostFt->params[$__i] ?? ValType::I32;
                            $wargs[] = match ($type) {
                                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF,   $raw === null ? -1 : (int)$raw),
                                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                                ValType::I64 => WasmValue::i64((int)$raw),
                                ValType::F32 => WasmValue::f32((float)$raw),
                                ValType::F64 => WasmValue::f64((float)$raw),
                                default      => WasmValue::i32((int)($raw ?? 0)),
                            };
                        }
                        $sp -= $pc;
                        $r = ($hostFuncs[$fIdx])($wargs);
                        $wresult = is_array($r) ? $r : ($r !== null ? [$r] : []);
                        foreach ($wresult as $rv) {
                            $stack[$sp++] = ($rv instanceof WasmValue)
                                ? ((($rv->type === ValType::FUNCREF || $rv->type === ValType::EXTERNREF) && $rv->value === -1) ? null : $rv->value)
                                : $rv;
                        }
                        break;
                    }
                    break;
                }

                case Op::RETURN_CALL: {
                    // Tail call — replace current frame in-place (no frame push)
                    $fIdx = $code[$ip++]; $pc = $paramCounts[$fIdx];
                    $_fa = $funcAll[$fIdx] ?? null;
                    if ($_fa !== null) {
                        $lbase = $sp - $pc;
                        { $_ld=$_fa[3]; if(is_int($_ld)){$_e=$sp+$_ld;while($sp<$_e)$stack[$sp++]=0;}else{foreach($_ld as $_v)$stack[$sp++]=$_v;} }
                        $code = $_fa[0];
                        $ip = 0; $len = $_fa[1]; $retCount = $_fa[2];
                        $retBase = -1;
                        break;
                    }
                    if (isset($rawHostFuncs[$fIdx])) {
                        $sp -= $pc;
                        $r = ($rawHostFuncs[$fIdx])($stack, $sp, $pc);
                        $retBase = $sp;
                        if (is_array($r)) {
                            foreach ($r as $rv) {
                                $stack[$sp++] = $rv;
                            }
                        } elseif ($r !== null) {
                            $stack[$sp++] = $r;
                        }
                        break 2;
                    }
                    if (isset($hostFuncs[$fIdx])) {
                        // Tail call to host: call it and return its result
                        $hostFt = $funcTypeFlat[$fIdx]; $wargs = [];
                        $__base = $sp - $pc;
                        for ($__i = 0; $__i < $pc; $__i++) {
                            $raw = $stack[$__base + $__i]; $type = $hostFt->params[$__i] ?? ValType::I32;
                            $wargs[] = match ($type) {
                                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF,   $raw === null ? -1 : (int)$raw),
                                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                                ValType::I64 => WasmValue::i64((int)$raw), ValType::F32 => WasmValue::f32((float)$raw),
                                ValType::F64 => WasmValue::f64((float)$raw), default => WasmValue::i32((int)($raw ?? 0)),
                            };
                        }
                        $sp -= $pc; $r = ($hostFuncs[$fIdx])($wargs);
                        $wresult = is_array($r) ? $r : ($r !== null ? [$r] : []);
                        $retBase = $sp;
                        foreach ($wresult as $rv) $stack[$sp++] = ($rv instanceof WasmValue) ? ((($rv->type === ValType::FUNCREF || $rv->type === ValType::EXTERNREF) && $rv->value === -1) ? null : $rv->value) : $rv;
                        break 2;
                    }
                    break; // unreachable — funcCode check covers all WASM funcs
                }

                case Op::CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $elemIdx  = (int)$stack[--$sp];
                    $pc       = $typeParamCounts[$typeIdx];
                    $table    = $tables[$tableIdx] ?? throw Trap::outOfBoundsTableAccess();
                    if ($elemIdx < 0 || $elemIdx >= $table->size) throw Trap::outOfBoundsTableAccess();
                    $fIdx = $table->elements[$elemIdx];
                    if ($fIdx === null) throw Trap::uninitializedElement();
                    if ($funcTypeIdxFlat[$fIdx] !== $typeIdx && !$modTypes[$typeIdx]->equals($funcTypeFlat[$fIdx]))
                        throw Trap::indirectCallTypeMismatch();
                    $_fa = $funcAll[$fIdx] ?? null;
                    if ($_fa !== null) {
                        // WASM-to-WASM call (most common)
                        if ($fsp >= self::MAX_CALL_DEPTH * 6) throw Trap::callStackExhausted();
                        $newLbase = $sp - $pc;
                        { $_ld=$_fa[3]; if(is_int($_ld)){$_e=$sp+$_ld;while($sp<$_e)$stack[$sp++]=0;}else{foreach($_ld as $_v)$stack[$sp++]=$_v;} }
                        $frameData[$fsp]=$code; $frameData[$fsp+1]=$ip; $frameData[$fsp+2]=$len; $frameData[$fsp+3]=$retCount;
                        $frameData[$fsp+4]=$lbase; $frameData[$fsp+5]=$newLbase;
                        $fsp += 6;
                        $code = $_fa[0]; $lbase = $newLbase;
                        $ip = 0; $len = $_fa[1]; $retCount = $_fa[2];
                        $retBase = -1;
                        break;
                    }
                    if (isset($rawHostFuncs[$fIdx])) {
                        $sp -= $pc;
                        $r = ($rawHostFuncs[$fIdx])($stack, $sp, $pc);
                        $retBase = $sp;
                        if (is_array($r)) {
                            foreach ($r as $rv) {
                                $stack[$sp++] = $rv;
                            }
                        } elseif ($r !== null) {
                            $stack[$sp++] = $r;
                        }
                        break 2;
                    }
                    if (isset($hostFuncs[$fIdx])) {
                        $hostFt = $funcTypeFlat[$fIdx]; $wargs = [];
                        $__base = $sp - $pc;
                        for ($__i = 0; $__i < $pc; $__i++) {
                            $raw = $stack[$__base + $__i]; $type = $hostFt->params[$__i] ?? ValType::I32;
                            $wargs[] = match ($type) {
                                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF,   $raw === null ? -1 : (int)$raw),
                                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                                ValType::I64 => WasmValue::i64((int)$raw), ValType::F32 => WasmValue::f32((float)$raw),
                                ValType::F64 => WasmValue::f64((float)$raw), default => WasmValue::i32((int)($raw ?? 0)),
                            };
                        }
                        $sp -= $pc; $r = ($hostFuncs[$fIdx])($wargs);
                        $wresult = is_array($r) ? $r : ($r !== null ? [$r] : []);
                        $retBase = $sp;
                        foreach ($wresult as $rv) $stack[$sp++] = ($rv instanceof WasmValue) ? ((($rv->type === ValType::FUNCREF || $rv->type === ValType::EXTERNREF) && $rv->value === -1) ? null : $rv->value) : $rv;
                        break 2;
                    }
                    // Tail call — replace frame in-place
                    $lbase    = $sp - $pc;
                    { $_ld=$funcLD[$fIdx]; if(is_int($_ld)){$_e=$sp+$_ld;while($sp<$_e)$stack[$sp++]=0;}else{foreach($_ld as $_v)$stack[$sp++]=$_v;} }
                    $code = $funcCode[$fIdx];
                    $ip = 0; $len = $funcCodeLen[$fIdx]; $retCount = $resultCounts[$fIdx];
                    $retBase = -1;
                    break;
                }

                case Op::RETURN_CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $elemIdx  = (int)$stack[--$sp];
                    $pc       = $typeParamCounts[$typeIdx];
                    $table    = $tables[$tableIdx] ?? throw Trap::outOfBoundsTableAccess();
                    if ($elemIdx < 0 || $elemIdx >= $table->size) throw Trap::outOfBoundsTableAccess();
                    $fIdx = $table->elements[$elemIdx];
                    if ($fIdx === null) throw Trap::uninitializedElement();
                    if ($funcTypeIdxFlat[$fIdx] !== $typeIdx && !$modTypes[$typeIdx]->equals($funcTypeFlat[$fIdx]))
                        throw Trap::indirectCallTypeMismatch();
                    $_fa = $funcAll[$fIdx] ?? null;
                    if ($_fa !== null) {
                        // Tail WASM-to-WASM call
                        $lbase = $sp - $pc;
                        { $_ld=$_fa[3]; if(is_int($_ld)){$_e=$sp+$_ld;while($sp<$_e)$stack[$sp++]=0;}else{foreach($_ld as $_v)$stack[$sp++]=$_v;} }
                        $code = $_fa[0];
                        $ip = 0; $len = $_fa[1]; $retCount = $_fa[2];
                        $retBase = -1;
                        break;
                    }
                    if (isset($rawHostFuncs[$fIdx])) {
                        $sp -= $pc;
                        $r = ($rawHostFuncs[$fIdx])($stack, $sp, $pc);
                        $retBase = $sp;
                        if (is_array($r)) {
                            foreach ($r as $rv) { $stack[$sp++] = $rv; }
                        } elseif ($r !== null) {
                            $stack[$sp++] = $r;
                        }
                        break 2;
                    }
                    if (isset($hostFuncs[$fIdx])) {
                        $hostFt = $funcTypeFlat[$fIdx]; $wargs = [];
                        $__base = $sp - $pc;
                        for ($__i = 0; $__i < $pc; $__i++) {
                            $raw = $stack[$__base + $__i]; $type = $hostFt->params[$__i] ?? ValType::I32;
                            $wargs[] = match ($type) {
                                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF,   $raw === null ? -1 : (int)$raw),
                                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                                ValType::I64 => WasmValue::i64((int)$raw), ValType::F32 => WasmValue::f32((float)$raw),
                                ValType::F64 => WasmValue::f64((float)$raw), default => WasmValue::i32((int)($raw ?? 0)),
                            };
                        }
                        $sp -= $pc; $r = ($hostFuncs[$fIdx])($wargs);
                        $wresult = is_array($r) ? $r : ($r !== null ? [$r] : []);
                        $retBase = $sp;
                        foreach ($wresult as $rv) $stack[$sp++] = ($rv instanceof WasmValue) ? ((($rv->type === ValType::FUNCREF || $rv->type === ValType::EXTERNREF) && $rv->value === -1) ? null : $rv->value) : $rv;
                        break 2;
                    }
                    break; // unreachable if funcCode check above covers all WASM funcs
                }
                case Op::DROP:   --$sp; break;
                case Op::SELECT: {
                    $c = (int)$stack[--$sp]; $b = $stack[--$sp]; $a = $stack[--$sp]; $stack[$sp++] = $c !== 0 ? $a : $b;
                    break;
                }

                // ---- Variable ----
                case Op::LOCAL_GET:  { $idx=$code[$ip++]; $stack[$sp++] = $stack[$lbase+$idx]; break; }
                case Op::LOCAL_SET:  { $idx=$code[$ip++]; $stack[$lbase+$idx] = $stack[--$sp]; break; }
                case Op::LOCAL_TEE:  { $idx=$code[$ip++]; $stack[$lbase+$idx] = $stack[$sp-1]; break; }
                case Op::SB_LGET_I32WRAP:  { $stack[$sp++] = (int)$stack[$lbase + $code[$ip++]] << 32 >> 32; break; }
                case Op::SB_LGET_I32SUB:   { $stack[$sp-1] = ((int)$stack[$sp-1] - (int)$stack[$lbase + $code[$ip++]]) << 32 >> 32; break; }
                case Op::SB_LTEE_ICONST:   { $stack[$lbase + $code[$ip]] = $stack[$sp-1]; $stack[$sp++] = $code[$ip+1]; $ip += 2; break; }
                case Op::SB_LTEE_I64CONST: { $stack[$lbase + $code[$ip]] = $stack[$sp-1]; $stack[$sp++] = $code[$ip+1]; $ip += 2; break; }
                case Op::SB_LTEE_BRIF: { // [teeIdx, targetIp, spDelta, rCnt] — local.tee + br_if
                    $teeIdx=$code[$ip++];$targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                    $v=$stack[$sp-1];$stack[$lbase+$teeIdx]=$v;$sp--;
                    if((int)$v!==0){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                    break; }
                case Op::GLOBAL_GET: $stack[$sp++] = $globals[$code[$ip++]]; break;
                case Op::GLOBAL_SET: $globals[$code[$ip++]] = $stack[--$sp]; break;

                // ---- Constants ----
                case Op::I32_CONST: $stack[$sp++] = $code[$ip++]; break;
                case Op::I64_CONST: $stack[$sp++] = $code[$ip++]; break;
                case Op::F32_CONST: $stack[$sp++] = $code[$ip++]; break;
                case Op::F64_CONST: $stack[$sp++] = $code[$ip++]; break;

                // ---- i32 arithmetic ----
                // sign32 inline: $stack[]= expr <<32>>32;  (branchless, |0xFFFF| mask no longer needed)
                // AND/OR/XOR/SHR_S/DIV_S/REM_S of two sign-extended i32s produce sign-extended i32 → no mask32
                case Op::I32_ADD: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a+$b)<<32>>32; break; }
                case Op::I32_SUB: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a-$b)<<32>>32; break; }
                                // ---- Super instructions (peephole-fused) ----
                                case Op::SB_LGET_ICONST_IADD: { // local.get $x + i32.const $c + i32.add
                                    $stack[$sp++] = ($stack[$lbase + $code[$ip]] + $code[$ip+1]) << 32 >> 32;
                                    $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST_IADD_I32LOAD: { // [x, c, off] — push mem[local[x]+c+off]
                                    $addr = ((((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) & 0xFFFFFFFF) + $code[$ip+2];
                                    $ip += 3;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = unpack('V', $bytes, $addr)[1] << 32 >> 32; break;
                                }
                                case Op::SB_LGET_ICONST_IADD_I32LOAD8U: { // [x, c, off] — push unsigned byte mem[local[x]+c+off]
                                    $addr = ((((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) & 0xFFFFFFFF) + $code[$ip+2];
                                    $ip += 3;
                                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = ord($bytes[$addr]); break;
                                }
                                case Op::SB_LGET_ICONST_IADD_I32LOAD_I32EQ_BRIF: { // [x,c,off,tag,tIp,spD,rCnt] — JSValue tag-check eq
                                    $addr = ((((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) & 0xFFFFFFFF) + $code[$ip+2];
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $v = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $tag=$code[$ip+3];$targetIp=$code[$ip+4];$spDelta=$code[$ip+5];$rCnt=$code[$ip+6];$ip+=7;
                                    if($v===$tag){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_IADD_I32LOAD_I32NE_BRIF: { // [x,c,off,tag,tIp,spD,rCnt] — JSValue tag-check ne
                                    $addr = ((((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) & 0xFFFFFFFF) + $code[$ip+2];
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $v = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $tag=$code[$ip+3];$targetIp=$code[$ip+4];$spDelta=$code[$ip+5];$rCnt=$code[$ip+6];$ip+=7;
                                    if($v!==$tag){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_I32LOAD: { // local.get $x + i32.load $off
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1];
                                    $ip += 2;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = unpack('V', $bytes, $addr)[1] << 32 >> 32; break;
                                }
                                case Op::SB_I32LOAD_LTEE: { // i32.load $off + local.tee $y  → [off, y]
                                    $addr = (((int)$stack[$sp - 1]) & 0xFFFFFFFF) + $code[$ip];
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $v = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $stack[$sp - 1] = $v; $stack[$lbase + $code[$ip + 1]] = $v; $ip += 2; break;
                                }
                                case Op::SB_LGET_I32LOAD_LTEE: { // local.get $x + i32.load $off + local.tee $y
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1];
                                    $teeIdx = $code[$ip+2]; $ip += 3;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $v = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $stack[$lbase + $teeIdx] = $v; $stack[$sp++] = $v; break;
                                }
                                case Op::SB_LGET_I32LOAD_LSET: { // local.get $x + i32.load $off + local.set $y  → [x, off, y]
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1];
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$lbase + $code[$ip+2]] = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $ip += 3; break;
                                }
                                case Op::SB_ICONST_LSET: { // i32.const $c + local.set $y  → [c, y]
                                    $stack[$lbase + $code[$ip+1]] = $code[$ip]; $ip += 2; break;
                                }
                                case Op::SB_I64CONST_LSET: { // i64.const $c + local.set $y  → [c, y]
                                    $stack[$lbase + $code[$ip+1]] = $code[$ip]; $ip += 2; break;
                                }
                                case Op::SB_LGET_LGET_I32STORE: { // local.get $a + local.get $b + i32.store $off  → [a, b, off]
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+2];
                                    $v = (int)$stack[$lbase + $code[$ip+1]]; $ip += 3;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $bytes[$addr]=$chrStr[$v&0xFF];$bytes[$addr+1]=$chrStr[($v>>8)&0xFF];$bytes[$addr+2]=$chrStr[($v>>16)&0xFF];$bytes[$addr+3]=$chrStr[($v>>24)&0xFF]; break;
                                }
                                case Op::SB_LGET_I32LOAD8U: { // local.get $x + i32.load8_u $off
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1];
                                    $ip += 2;
                                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = ord($bytes[$addr]); break;
                                }
                                case Op::SB_LGET_I32LOAD8U_LTEE: { // local.get $x + i32.load8_u $off + local.tee $y
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1];
                                    $teeIdx = $code[$ip+2]; $ip += 3;
                                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $v = ord($bytes[$addr]);
                                    $stack[$lbase + $teeIdx] = $v; $stack[$sp++] = $v; break;
                                }
                                case Op::SB_LGET_ICONST_IADD_LSET: { // local.get $x + i32.const $c + i32.add + local.set $y
                                    $stack[$lbase + $code[$ip+2]] = ($stack[$lbase + $code[$ip]] + $code[$ip+1]) << 32 >> 32;
                                    $ip += 3; break;
                                }
                                case Op::SB_LGET_ICONST_IADD_LTEE: { // local.get $x + i32.const $c + i32.add + local.tee $y
                                    $v = ($stack[$lbase + $code[$ip]] + $code[$ip+1]) << 32 >> 32;
                                    $stack[$lbase + $code[$ip+2]] = $v; $stack[$sp++] = $v;
                                    $ip += 3; break;
                                }
                                case Op::SB_LGET_ICONST_IADD_LTEE_BRIF_LOOP: { // [x,c,y,targetIp,spDelta,rCnt]
                                    $v=(((int)$stack[$lbase+$code[$ip]])+$code[$ip+1])<<32>>32;
                                    $stack[$lbase+$code[$ip+2]]=$v;
                                    $targetIp=$code[$ip+3];$spDelta=$code[$ip+4];$rCnt=$code[$ip+5];$ip+=6;
                                    if($v!==0){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD: { // local.get+i32.const+i32.add+local.tee+i32.load [x,c,y,off]
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) << 32 >> 32;
                                    $stack[$lbase + $code[$ip+2]] = $addr;
                                    $raddr = ($addr & 0xFFFFFFFF) + $code[$ip+3]; $ip += 4;
                                    if ($raddr < 0 || $raddr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = unpack('V', $bytes, $raddr)[1] << 32 >> 32;
                                    break;
                                }
                                case Op::SB_LGET_ICONST_IADD_LTEE_I32LOAD8U: { // local.get+i32.const+i32.add+local.tee+i32.load8_u [x,c,y,off]
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) + $code[$ip+1]) << 32 >> 32;
                                    $stack[$lbase + $code[$ip+2]] = $addr;
                                    $raddr = ($addr & 0xFFFFFFFF) + $code[$ip+3]; $ip += 4;
                                    if ($raddr < 0 || $raddr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = ord($bytes[$raddr]);
                                    break;
                                }
                                case Op::SB_I32EQZ_BRIF: { // [targetIp,spDelta,rCnt] branch if TOS==0
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $cond=(int)$stack[--$sp];
                                    if($cond===0){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_ICONST_IADD: { // i32.const $c + i32.add
                                    $stack[$sp - 1] = (((int)$stack[$sp - 1]) + $code[$ip++]) << 32 >> 32;
                                    break;
                                }
                                case Op::SB_I64CONST_I64AND: { // i64.const $c + i64.and  → [c]
                                    $stack[$sp - 1] = ((int)$stack[$sp - 1]) & $code[$ip++]; break;
                                }
                                case Op::SB_ICONST_I32AND: { // i32.const $c + i32.and  → [c]
                                    $stack[$sp - 1] = ((int)$stack[$sp - 1]) & $code[$ip++]; break;
                                }
                                case Op::SB_LGET_I64LOAD: { // local.get $x + i64.load $off  → [x, off]
                                    $addr = (((int)$stack[$lbase + $code[$ip]]) & 0xFFFFFFFF) + $code[$ip+1]; $ip += 2;
                                    if ($addr < 0 || $addr + 8 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp++] = unpack('P', $bytes, $addr)[1]; break;
                                }
                                case Op::SB_LGET_I32ADD: { // local.get $x + i32.add (add local to TOS)  → [x]
                                    $stack[$sp-1] = (((int)$stack[$sp-1]) + (int)$stack[$lbase + $code[$ip++]]) << 32 >> 32; break;
                                }
                                case Op::SB_LGET_I64CONST: { // local.get $x + i64.const $c → [x, c]
                                    $stack[$sp] = $stack[$lbase + $code[$ip]]; $stack[$sp+1] = $code[$ip+1]; $sp += 2; $ip += 2; break;
                                }
                                case Op::SB_LGET_I64CONST_I64AND: { // local.get $x + i64.const $c + i64.and → [x, c]
                                    $stack[$sp++] = (int)$stack[$lbase + $code[$ip]] & (int)$code[$ip+1]; $ip += 2; break;
                                }
                                case Op::SB_ICONST_I32SHL: { // i32.const $c + i32.shl (shift TOS left by c) → [c]
                                    $stack[$sp-1] = ((int)$stack[$sp-1] << ($code[$ip++] & 31)) << 32 >> 32; break;
                                }
                                case Op::SB_I32SUB_LTEE: { // i32.sub + local.tee $y → [y]
                                    $v = ((int)$stack[$sp-2] - (int)$stack[$sp-1]) << 32 >> 32; $sp--;
                                    $stack[$sp-1] = $v; $stack[$lbase + $code[$ip++]] = $v; break;
                                }
                                case Op::SB_I64CONST_I64STORE: { // i64.const $c + i64.store $off → [c, off]
                                    $v=(int)$code[$ip]; $off=$code[$ip+1]; $ip+=2; $addr=(((int)$stack[--$sp])&0xFFFFFFFF)+$off;
                                    if($addr<0||$addr+8>$blimit)throw Trap::outOfBoundsMemoryAccess();
                                    $p=pack('VV',$v,$v>>32);$bytes[$addr]=$p[0];$bytes[$addr+1]=$p[1];$bytes[$addr+2]=$p[2];$bytes[$addr+3]=$p[3];$bytes[$addr+4]=$p[4];$bytes[$addr+5]=$p[5];$bytes[$addr+6]=$p[6];$bytes[$addr+7]=$p[7];break;
                                }
                                case Op::SB_LGET_ICONST_I32STORE: { // local.get $x + i32.const $c + i32.store $off → [x, c, off]
                                    $addr = ((int)$stack[$lbase + $code[$ip]] & 0xFFFFFFFF) + $code[$ip+2]; $v = $code[$ip+1]; $ip += 3;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $bytes[$addr]=$chrStr[$v&0xFF];$bytes[$addr+1]=$chrStr[($v>>8)&0xFF];$bytes[$addr+2]=$chrStr[($v>>16)&0xFF];$bytes[$addr+3]=$chrStr[($v>>24)&0xFF]; break;
                                }
                                case Op::SB_LGET_ICONST_I32AND: { // local.get $x + i32.const $c + i32.and → [x, c]
                                    $stack[$sp++] = (int)$stack[$lbase + $code[$ip]] & $code[$ip+1]; $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST_I32OR: { // local.get $x + i32.const $c + i32.or → [x, c]
                                    $stack[$sp++] = (int)$stack[$lbase + $code[$ip]] | $code[$ip+1]; $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST_I32SHL: { // local.get $x + i32.const $c + i32.shl → [x, c]
                                    $stack[$sp++] = ((int)$stack[$lbase + $code[$ip]] << ($code[$ip+1] & 31)) << 32 >> 32; $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST_I32SHR_S: { // local.get $x + i32.const $c + i32.shr_s → [x, c]
                                    $stack[$sp++] = (int)$stack[$lbase + $code[$ip]] >> ($code[$ip+1] & 31); $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST_I32SHR_U: { // local.get $x + i32.const $c + i32.shr_u → [x, c]
                                    $stack[$sp++] = (((int)$stack[$lbase + $code[$ip]] & 0xFFFFFFFF) >> ($code[$ip+1] & 31)) << 32 >> 32; $ip += 2; break;
                                }
                                case Op::SB_LGET_LGET_I32ADD: { // local.get $a + local.get $b + i32.add → [a, b]
                                    $stack[$sp++] = ((int)$stack[$lbase + $code[$ip]] + (int)$stack[$lbase + $code[$ip+1]]) << 32 >> 32; $ip += 2; break;
                                }
                                case Op::SB_ICONST_IADD_I32STORE: { // i32.const $c + i32.add + i32.store $off  → [c, off]
                                    $addr = (((int)$stack[$sp - 2]) & 0xFFFFFFFF) + $code[$ip + 1];
                                    $v = (((int)$stack[$sp - 1]) + $code[$ip]) << 32 >> 32; $ip += 2; $sp -= 2;
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $bytes[$addr]=$chrStr[$v&0xFF];$bytes[$addr+1]=$chrStr[($v>>8)&0xFF];$bytes[$addr+2]=$chrStr[($v>>16)&0xFF];$bytes[$addr+3]=$chrStr[($v>>24)&0xFF]; break;
                                }
                                case Op::SB_LGET_LGET: { // local.get $x + local.get $y
                                    $idxA = $lbase + $code[$ip++];
                                    $idxB = $lbase + $code[$ip++];
                                    $stack[$sp++] = $stack[$idxA];
                                    $stack[$sp++] = $stack[$idxB];
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32LOAD: { // [a, b, off] — push local[a], load mem[local[b]+off]
                                    $addr = (((int)$stack[$lbase + $code[$ip+1]]) & 0xFFFFFFFF) + $code[$ip+2];
                                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                                    $stack[$sp] = $stack[$lbase + $code[$ip]];
                                    $stack[$sp+1] = unpack('V', $bytes, $addr)[1] << 32 >> 32;
                                    $sp += 2; $ip += 3; break;
                                }
                                case Op::SB_LGET_LSET: { // local.get $src + local.set $dst  → [src, dst]
                                    $stack[$lbase + $code[$ip+1]] = $stack[$lbase + $code[$ip]]; $ip += 2; break;
                                }
                                case Op::SB_LGET_ICONST: { // local.get $x + i32.const $c
                                    $stack[$sp++] = $stack[$lbase + $code[$ip++]];
                                    $stack[$sp++] = $code[$ip++];
                                    break;
                                }
                                case Op::SB_LGET_I32WRAP_LTEE: { // local.get $x + i32.wrap_i64 + local.tee $y
                                    $v = (int)$stack[$lbase + $code[$ip++]] << 32 >> 32;
                                    $teeIdx = $code[$ip++];
                                    $stack[$lbase + $teeIdx] = $v;
                                    $stack[$sp++] = $v;
                                    break;
                                }
                                case Op::SB_I64LTU_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a!==$b){$as=($a>>63)&1;$bs=($b>>63)&1;$cmp=$as!==$bs?($as>$bs?1:-1):($a<=>$b);if($cmp<0){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}}
                                    break;
                                }
                                case Op::SB_I64CONST_I64LTU_BRIF: { // [c,targetIp,spDelta,rCnt]
                                    $c=$code[$ip++];$targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $a=(int)$stack[--$sp];
                                    if($a!==$c){$as=($a>>63)&1;$cs=($c>>63)&1;$take=($as!==$cs)?($as===0):($a<$c);}else{$take=false;}
                                    if($take){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }

                                case Op::SB_LGET_I64CONST_I64LTU_BRIF: { // [x,c,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$c=$code[$ip+1];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a!==$c){$as=($a>>63)&1;$cs=($c>>63)&1;$take=($as!==$cs)?($as===0):($a<$c);}else{$take=false;}
                                    if($take){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }

                                case Op::SB_I32NE_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a!==$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32GTS_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a>$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_I32GTS_BRIF: { // [x,c,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=$code[$ip+1];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a>$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_I32NE_BRIF: { // [x,c,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=$code[$ip+1];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a!==$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_I32LTS_BRIF: { // [x,c,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=$code[$ip+1];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a<$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_ICONST_I32EQ_BRIF: { // [x,c,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=$code[$ip+1];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a===$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32NE_BRIF: { // [a,b,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=(int)$stack[$lbase+$code[$ip+1]];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a!==$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32EQ_BRIF: { // [a,b,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=(int)$stack[$lbase+$code[$ip+1]];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a===$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32LTS_BRIF: { // [a,b,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=(int)$stack[$lbase+$code[$ip+1]];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a<$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32GTS_BRIF: { // [a,b,targetIp,spDelta,rCnt]
                                    $a=(int)$stack[$lbase+$code[$ip]];$b=(int)$stack[$lbase+$code[$ip+1]];$targetIp=$code[$ip+2];$spDelta=$code[$ip+3];$rCnt=$code[$ip+4];$ip+=5;
                                    if($a>$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_LGET_LGET_I32SUB: { // [a,b]
                                    $stack[$sp++] = ((int)$stack[$lbase+$code[$ip]] - (int)$stack[$lbase+$code[$ip+1]]) << 32 >> 32; $ip += 2; break;
                                }

                                case Op::SB_I32LTS_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a<$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32EQ_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a===$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32CONST_I32EQ_BRIF: { // [c,targetIp,spDelta,rCnt] — pops 1 TOS, compares with code-immediate c
                                    $c=$code[$ip++];$targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $a=(int)$stack[--$sp];
                                    if($a===$c){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32CONST_I32NE_BRIF: { // [c,targetIp,spDelta,rCnt] — pops 1 TOS, compares with code-immediate c
                                    $c=$code[$ip++];$targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $a=(int)$stack[--$sp];
                                    if($a!==$c){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32GTU_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=((int)$stack[--$sp])&0xFFFFFFFF;$a=((int)$stack[--$sp])&0xFFFFFFFF;
                                    if($a>$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I64EQ_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a===$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }

                                case Op::SB_I32LTU_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=((int)$stack[--$sp])&0xFFFFFFFF;$a=((int)$stack[--$sp])&0xFFFFFFFF;
                                    if($a<$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32LES_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a<=$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32LEU_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=((int)$stack[--$sp])&0xFFFFFFFF;$a=((int)$stack[--$sp])&0xFFFFFFFF;
                                    if($a<=$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32GES_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a>=$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I32GEU_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=((int)$stack[--$sp])&0xFFFFFFFF;$a=((int)$stack[--$sp])&0xFFFFFFFF;
                                    if($a>=$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }
                                case Op::SB_I64NE_BRIF: { // [targetIp,spDelta,rCnt]
                                    $targetIp=$code[$ip++];$spDelta=$code[$ip++];$rCnt=$code[$ip++];
                                    $b=(int)$stack[--$sp];$a=(int)$stack[--$sp];
                                    if($a!==$b){if($rCnt>0&&$spDelta!==0){$srcBase=$sp-$rCnt;$dstBase=$srcBase+$spDelta;for($__i=0;$__i<$rCnt;$__i++)$stack[$dstBase+$__i]=$stack[$srcBase+$__i];}$sp+=$spDelta;$ip=$targetIp;}
                                    break;
                                }


















                case Op::I32_MUL: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a*$b)<<32>>32; break; }
                case Op::I32_DIV_S: {
                    $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp];
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===-2147483648 && $b===-1) throw Trap::integerOverflow();
                    $stack[$sp++]=intdiv($a,$b); break;  // result always in i32 range
                }
                case Op::I32_DIV_U: {
                    $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF;
                    if ($b===0) throw Trap::integerDivideByZero();
                        $stack[$sp++]=(int)($a/$b)<<32>>32; break;
                }
                case Op::I32_REM_S: {
                    $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp];
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[$sp++]=$a%$b; break;  // result always in i32 range
                }
                case Op::I32_REM_U: {
                    $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF;
                    if ($b===0) throw Trap::integerDivideByZero();
                        $stack[$sp++]=($a%$b)<<32>>32; break;
                }
                case Op::I32_AND:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a&$b; break; }
                case Op::I32_OR:    { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a|$b; break; }
                case Op::I32_XOR:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a^$b; break; }
                case Op::I32_SHL:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a<<($b&31))<<32>>32; break; }
                case Op::I32_SHR_S: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a>>($b&31); break; }  // already sign-extended
                case Op::I32_SHR_U: {
                    $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF;
                        $stack[$sp++]=($a>>($b&31))<<32>>32; break;
                }
                case Op::I32_ROTL: {
                    $b=((int)$stack[--$sp])&31; $a=((int)$stack[--$sp])&0xFFFFFFFF;
                        $stack[$sp++]=(($a<<$b)|($a>>(32-$b)))<<32>>32; break;
                }
                case Op::I32_ROTR: {
                    $b=((int)$stack[--$sp])&31; $a=((int)$stack[--$sp])&0xFFFFFFFF;
                        $stack[$sp++]=(($a>>$b)|($a<<(32-$b)))<<32>>32; break;
                }
                case Op::I32_CLZ:    { $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=$a===0?32:self::clz32($a); break; }
                case Op::I32_CTZ:    { $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=$a===0?32:self::ctz($a); break; }
                case Op::I32_POPCNT: { $a=((int)$stack[--$sp])&0xFFFFFFFF; $n=0; while($a){$n+=$a&1;$a>>=1;} $stack[$sp++]=$n; break; }
                case Op::I32_EQZ:    $stack[$sp-1]=((int)$stack[$sp-1]===0)?1:0; break;
                case Op::I32_EQ:     { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a===$b)?1:0; break; }
                case Op::I32_NE:     { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a!==$b)?1:0; break; }
                case Op::I32_LT_S:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a<$b)?1:0; break; }
                case Op::I32_LT_U:   { $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=($a<$b)?1:0; break; }
                case Op::I32_GT_S:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a>$b)?1:0; break; }
                case Op::I32_GT_U:   { $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=($a>$b)?1:0; break; }
                case Op::I32_LE_S:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a<=$b)?1:0; break; }
                case Op::I32_LE_U:   { $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=($a<=$b)?1:0; break; }
                case Op::I32_GE_S:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a>=$b)?1:0; break; }
                case Op::I32_GE_U:   { $b=((int)$stack[--$sp])&0xFFFFFFFF; $a=((int)$stack[--$sp])&0xFFFFFFFF; $stack[$sp++]=($a>=$b)?1:0; break; }

                // ---- i64 arithmetic ----
                case Op::I64_ADD: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $r=$a+$b; if(is_int($r)){$stack[$sp++]=$r;}else{$lo=($a&0xFFFFFFFF)+($b&0xFFFFFFFF);$stack[$sp++]=((((($a>>32)&0xFFFFFFFF)+(($b>>32)&0xFFFFFFFF)+($lo>>32&1))&0xFFFFFFFF)<<32)|($lo&0xFFFFFFFF);} break; }
                case Op::I64_SUB: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $r=$a-$b; if(is_int($r)){$stack[$sp++]=$r;}else{$lo=($a&0xFFFFFFFF)-($b&0xFFFFFFFF);$bw=($lo<0)?1:0;$stack[$sp++]=((((($a>>32)&0xFFFFFFFF)-(($b>>32)&0xFFFFFFFF)-$bw)&0xFFFFFFFF)<<32)|($lo&0xFFFFFFFF);} break; }
                case Op::I64_MUL: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $r=$a*$b; $stack[$sp++]=is_int($r)?$r:self::int64Mul($a,$b); break; }
                case Op::I64_DIV_S: {
                    $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp];
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===PHP_INT_MIN && $b===-1) throw Trap::integerOverflow();
                    $stack[$sp++]=intdiv($a,$b); break;
                }
                case Op::I64_DIV_U: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($b===0) throw Trap::integerDivideByZero(); $stack[$sp++]=self::u64div($a,$b); break; }
                case Op::I64_REM_S: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($b===0) throw Trap::integerDivideByZero(); $stack[$sp++]=$a%$b; break; }
                case Op::I64_REM_U: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($b===0) throw Trap::integerDivideByZero(); $stack[$sp++]=self::u64rem($a,$b); break; }
                case Op::I64_AND:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a&$b; break; }
                case Op::I64_OR:    { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a|$b; break; }
                case Op::I64_XOR:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a^$b; break; }
                case Op::I64_SHL:   { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a<<($b&63); break; }
                case Op::I64_SHR_S: { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=$a>>($b&63); break; }
                case Op::I64_SHR_U: { $b=((int)$stack[--$sp])&63; $a=(int)$stack[--$sp]; if($b===0){$stack[$sp++]=$a;}elseif($a>=0){$stack[$sp++]=$a>>$b;}elseif($b>=63){$stack[$sp++]=1;}else{$hi=($a>>32)&0xFFFFFFFF;$lo=$a&0xFFFFFFFF;if($b<32){$stack[$sp++]=((($hi>>$b)&((1<<(32-$b))-1))<<32)|(($lo>>$b|$hi<<(32-$b))&0xFFFFFFFF);}else{$stack[$sp++]=($hi>>($b-32))&((1<<(64-$b))-1);}} break; }
                case Op::I64_ROTL:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $b&=63; if($b===0){$stack[$sp++]=$a;}else{$s=64-$b;if($a>=0){$shr=$a>>$s;}elseif($s>=63){$shr=1;}else{$hi=($a>>32)&0xFFFFFFFF;$lo=$a&0xFFFFFFFF;if($s<32){$shr=((($hi>>$s)&((1<<(32-$s))-1))<<32)|(($lo>>$s|$hi<<(32-$s))&0xFFFFFFFF);}else{$shr=($hi>>($s-32))&((1<<(64-$s))-1);}}$stack[$sp++]=($a<<$b)|$shr;} break; }
                case Op::I64_ROTR:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $b&=63; if($b===0){$stack[$sp++]=$a;}else{if($a>=0){$shr=$a>>$b;}elseif($b>=63){$shr=1;}else{$hi=($a>>32)&0xFFFFFFFF;$lo=$a&0xFFFFFFFF;if($b<32){$shr=((($hi>>$b)&((1<<(32-$b))-1))<<32)|(($lo>>$b|$hi<<(32-$b))&0xFFFFFFFF);}else{$shr=($hi>>($b-32))&((1<<(64-$b))-1);}}$stack[$sp++]=$shr|($a<<(64-$b));} break; }
                case Op::I64_CLZ:   { $a=(int)$stack[--$sp]; $stack[$sp++]=$a===0?64:self::clz64($a); break; }
                case Op::I64_CTZ:   { $a=(int)$stack[--$sp]; $stack[$sp++]=$a===0?64:self::ctz($a); break; }
                case Op::I64_POPCNT:{ $a=(int)$stack[--$sp]; $n=0; for($b=0;$b<64;$b++){if(($a>>$b)&1)$n++;} $stack[$sp++]=$n; break; }
                case Op::I64_EQZ:   $stack[$sp-1]=((int)$stack[$sp-1]===0)?1:0; break;
                case Op::I64_EQ:    { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a===$b)?1:0; break; }
                case Op::I64_NE:    { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a!==$b)?1:0; break; }
                case Op::I64_LT_S:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a<$b)?1:0; break; }
                case Op::I64_LT_U:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($a===$b){$r=0;}else{$as=($a>>63)&1;$bs=($b>>63)&1;$r=$as!==$bs?($as>$bs?1:-1):($a<=>$b);} $stack[$sp++]=$r<0?1:0; break; }
                case Op::I64_GT_S:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a>$b)?1:0; break; }
                case Op::I64_GT_U:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($a===$b){$r=0;}else{$as=($a>>63)&1;$bs=($b>>63)&1;$r=$as!==$bs?($as>$bs?1:-1):($a<=>$b);} $stack[$sp++]=$r>0?1:0; break; }
                case Op::I64_LE_S:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a<=$b)?1:0; break; }
                case Op::I64_LE_U:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($a===$b){$r=0;}else{$as=($a>>63)&1;$bs=($b>>63)&1;$r=$as!==$bs?($as>$bs?1:-1):($a<=>$b);} $stack[$sp++]=$r<=0?1:0; break; }
                case Op::I64_GE_S:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; $stack[$sp++]=($a>=$b)?1:0; break; }
                case Op::I64_GE_U:  { $b=(int)$stack[--$sp]; $a=(int)$stack[--$sp]; if($a===$b){$r=0;}else{$as=($a>>63)&1;$bs=($b>>63)&1;$r=$as!==$bs?($as>$bs?1:-1):($a<=>$b);} $stack[$sp++]=$r>=0?1:0; break; }

                // ---- f32 arithmetic ----
                case Op::F32_ADD:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32($a+$b); break; }
                case Op::F32_SUB:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32($a-$b); break; }
                case Op::F32_MUL:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32($a*$b); break; }
                case Op::F32_DIV:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32(self::fdiv($a,$b)); break; }
                case Op::F32_MIN:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32(self::fmin($a,$b)); break; }
                case Op::F32_MAX:     { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32(self::fmax($a,$b)); break; }
                case Op::F32_ABS: {
                    $v=$stack[--$sp];
                    if (is_int($v)) { $stack[$sp++]=$v&0x7FFFFFFF; }
                    else { $stack[$sp++]=WasmValue::canonF32(abs((float)$v)); }
                    break;
                }
                case Op::F32_NEG: {
                    $v=$stack[--$sp];
                    if (is_int($v)) { $stack[$sp++]=$v^(int)0x80000000; }
                    else { $stack[$sp++]=WasmValue::canonF32(-(float)$v); }
                    break;
                }
                case Op::F32_SQRT:    { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32(sqrt(self::asF32($v))); break; }
                case Op::F32_CEIL:    { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32(ceil(self::asF32($v))); break; }
                case Op::F32_FLOOR:   { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32(floor(self::asF32($v))); break; }
                case Op::F32_TRUNC:   { $a=self::asF32($stack[--$sp]); $stack[$sp++]=WasmValue::canonF32($a>=0?floor($a):ceil($a)); break; }
                case Op::F32_NEAREST: { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32(self::nearest(self::asF32($v))); break; }
                case Op::F32_COPYSIGN:{
                    $bv=$stack[--$sp]; $av=$stack[--$sp];
                    $aBits=is_int($av)?$av:WasmValue::f32Bits((float)$av);
                    $bBits=is_int($bv)?$bv:WasmValue::f32Bits((float)$bv);
                    $result=($aBits&0x7FFFFFFF)|($bBits&(int)0x80000000);
                    if (($result&0x7FFFFFFF)>0x7F800000) { $stack[$sp++]=$result; }
                    else { $stack[$sp++]=(float)unpack('f',pack('V',$result))[1]; }
                    break;
                }
                case Op::F32_EQ:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a===$b)?1:0; break; }
                case Op::F32_NE:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a!==$b)?1:0; break; }
                case Op::F32_LT:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a<$b)?1:0; break; }
                case Op::F32_GT:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a>$b)?1:0; break; }
                case Op::F32_LE:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a<=$b)?1:0; break; }
                case Op::F32_GE:  { $b=self::asF32($stack[--$sp]); $a=self::asF32($stack[--$sp]); $stack[$sp++]=($a>=$b)?1:0; break; }

                // ---- f64 arithmetic ----
                case Op::F64_ADD:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=$a+$b; break; }
                case Op::F64_SUB:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=$a-$b; break; }
                case Op::F64_MUL:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=$a*$b; break; }
                case Op::F64_DIV:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=self::fdiv($a,$b); break; }
                case Op::F64_MIN:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=self::fmin($a,$b); break; }
                case Op::F64_MAX:     { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=self::fmax($a,$b); break; }
                case Op::F64_ABS:     { $v=$stack[--$sp]; $stack[$sp++]=abs((float)$v); break; }
                case Op::F64_NEG:     { $v=$stack[--$sp]; $stack[$sp++]=-(float)$v; break; }
                case Op::F64_SQRT:    { $v=$stack[--$sp]; $stack[$sp++]=sqrt((float)$v); break; }
                case Op::F64_CEIL:    { $v=$stack[--$sp]; $stack[$sp++]=ceil((float)$v); break; }
                case Op::F64_FLOOR:   { $v=$stack[--$sp]; $stack[$sp++]=floor((float)$v); break; }
                case Op::F64_TRUNC:   { $a=(float)$stack[--$sp]; $stack[$sp++]=$a>=0?floor($a):ceil($a); break; }
                case Op::F64_NEAREST: { $v=$stack[--$sp]; $stack[$sp++]=self::nearest((float)$v); break; }
                case Op::F64_COPYSIGN:{ $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=self::copysign($a,$b); break; }
                case Op::F64_EQ:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a===$b)?1:0; break; }
                case Op::F64_NE:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a!==$b)?1:0; break; }
                case Op::F64_LT:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a<$b)?1:0; break; }
                case Op::F64_GT:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a>$b)?1:0; break; }
                case Op::F64_LE:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a<=$b)?1:0; break; }
                case Op::F64_GE:  { $b=(float)$stack[--$sp]; $a=(float)$stack[--$sp]; $stack[$sp++]=($a>=$b)?1:0; break; }

                // ---- Conversions ----
                case Op::I32_WRAP_I64:       { $v=$stack[--$sp]; $stack[$sp++]=(int)$v<<32>>32; break; }
                case Op::I32_TRUNC_F32_S:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I32s(self::asF32($v)); break; }
                case Op::I32_TRUNC_F32_U:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I32u(self::asF32($v)); break; }
                case Op::I32_TRUNC_F64_S:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I32s((float)$v); break; }
                case Op::I32_TRUNC_F64_U:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I32u((float)$v); break; }
                case Op::I32_TRUNC_SAT_F32_S:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI32s(self::asF32($v)); break; }
                case Op::I32_TRUNC_SAT_F32_U:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI32u(self::asF32($v)); break; }
                case Op::I32_TRUNC_SAT_F64_S:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI32s((float)$v); break; }
                case Op::I32_TRUNC_SAT_F64_U:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI32u((float)$v); break; }
                case Op::I64_EXTEND_I32_S:   { $v=$stack[--$sp]; $stack[$sp++]=(int)$v<<32>>32; break; }
                case Op::I64_EXTEND_I32_U:   { $stack[$sp-1]=(int)$stack[$sp-1]&0xFFFFFFFF; break; }
                case Op::I64_TRUNC_F32_S:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I64s(self::asF32($v)); break; }
                case Op::I64_TRUNC_F32_U:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I64u(self::asF32($v)); break; }
                case Op::I64_TRUNC_F64_S:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I64s((float)$v); break; }
                case Op::I64_TRUNC_F64_U:    { $v=$stack[--$sp]; $stack[$sp++]=self::truncF2I64u((float)$v); break; }
                case Op::I64_TRUNC_SAT_F64_S:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI64s((float)$v); break; }
                case Op::I64_TRUNC_SAT_F64_U:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI64u((float)$v); break; }
                case Op::I64_TRUNC_SAT_F32_S:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI64s(self::asF32($v)); break; }
                case Op::I64_TRUNC_SAT_F32_U:{ $v=$stack[--$sp]; $stack[$sp++]=self::truncSatI64u(self::asF32($v)); break; }
                case Op::F32_CONVERT_I32_S:  { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32((float)(int)$v); break; }
                case Op::F32_CONVERT_I32_U:  { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32((float)((int)$v&0xFFFFFFFF)); break; }
                case Op::F32_CONVERT_I64_S:  { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32((float)(int)$v); break; }
                case Op::F32_CONVERT_I64_U:  { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32(self::u64toFloat((int)$v)); break; }
                case Op::F32_DEMOTE_F64:     { $v=$stack[--$sp]; $stack[$sp++]=WasmValue::canonF32((float)$v); break; }
                case Op::F64_CONVERT_I32_S:  { $v=$stack[--$sp]; $stack[$sp++]=(float)(int)$v; break; }
                case Op::F64_CONVERT_I32_U:  { $v=$stack[--$sp]; $stack[$sp++]=(float)((int)$v&0xFFFFFFFF); break; }
                case Op::F64_CONVERT_I64_S:  { $v=$stack[--$sp]; $stack[$sp++]=(float)(int)$v; break; }
                case Op::F64_CONVERT_I64_U:  { $v=$stack[--$sp]; $stack[$sp++]=self::u64toFloat((int)$v); break; }
                case Op::F64_PROMOTE_F32:    { $v=$stack[--$sp]; $stack[$sp++]=self::asF32($v); break; }
                case Op::I32_REINTERPRET_F32: {
                    $v=$stack[--$sp];
                    $bits=is_int($v)?($v&0xFFFFFFFF):(unpack('V',pack('f',(float)$v))[1]&0xFFFFFFFF);
                        $stack[$sp++]=$bits<<32>>32;
                    break;
                }
                case Op::I64_REINTERPRET_F64: {
                    $p=pack('d',(float)$stack[--$sp]);
                    $r=unpack('V2',$p);
                    $stack[$sp++]=($r[2]<<32)|($r[1]&0xFFFFFFFF); break;
                }
                case Op::F32_REINTERPRET_I32: {
                    $bits=((int)$stack[--$sp])&0xFFFFFFFF;
                    if (($bits & 0x7FFFFFFF) > 0x7F800000) {
                            $stack[$sp++]=$bits<<32>>32;
                    } else {
                        $stack[$sp++]=(float)unpack('f',pack('V',$bits))[1];
                    }
                    break;
                }
                case Op::F64_REINTERPRET_I64: {
                    $v=(int)$stack[--$sp];
                    $stack[$sp++]=unpack('d',pack('VV',$v&0xFFFFFFFF,($v>>32)&0xFFFFFFFF))[1]; break;
                }
                case Op::I32_EXTEND8_S:  { $v=(int)$stack[--$sp]; $stack[$sp++]=$v<<56>>56; break; }
                case Op::I32_EXTEND16_S: { $v=(int)$stack[--$sp]; $stack[$sp++]=$v<<48>>48; break; }
                case Op::I64_EXTEND8_S:  { $v=(int)$stack[--$sp]; $stack[$sp++]=$v<<56>>56; break; }
                case Op::I64_EXTEND16_S: { $v=(int)$stack[--$sp]; $stack[$sp++]=$v<<48>>48; break; }
                case Op::I64_EXTEND32_S: { $v=(int)$stack[--$sp]; $stack[$sp++]=$v<<32>>32; break; }

                // ---- Memory ----
                case Op::MEMORY_SIZE: $stack[$sp++]=$mem0->size(); break;
                case Op::MEMORY_GROW: { $v=$stack[--$sp]; $stack[$sp++]=$mem0->grow((int)$v); $blimit=$mem0->limit; if ($balloc < $blimit) { $bytes .= str_repeat("\0", $blimit - $balloc); $balloc = $blimit; $mem0->allocated = $balloc; } break; }
                // Inline hot memory ops to avoid PHP method call overhead
                // Pattern: bounds check, lazy zero-extend, read/write $mem0->bytes directly
                case Op::I32_LOAD: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = unpack('V', $bytes, $addr)[1] << 32 >> 32; break;
                }
                case Op::I32_LOAD8_U: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = ord($bytes[$addr]); break;
                }
                case Op::I32_LOAD8_S: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = ord($bytes[$addr]) << 56 >> 56; break;
                }
                case Op::I32_LOAD16_U: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = unpack('v', $bytes, $addr)[1]; break;
                }
                case Op::I32_LOAD16_S: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = unpack('v', $bytes, $addr)[1] << 48 >> 48; break;
                }
                case Op::I64_LOAD: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 8 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = unpack('P', $bytes, $addr)[1]; break;
                }
                case Op::SB_I64LOAD_LTEE: { // i64.load $off + local.tee $y  → [off, y]
                    $addr = (((int)$stack[$sp-1]) & 0xFFFFFFFF) + $code[$ip];
                    if ($addr < 0 || $addr + 8 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $v = unpack('P', $bytes, $addr)[1];
                    $stack[$sp-1] = $v; $stack[$lbase + $code[$ip+1]] = $v; $ip += 2; break;
                }
                case Op::I64_LOAD8_S: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = ord($bytes[$addr]) << 56 >> 56; break;
                }
                case Op::I64_LOAD8_U: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = ord($bytes[$addr]); break;
                }
                case Op::I64_LOAD16_S: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = unpack('v', $bytes, $addr)[1] << 48 >> 48; break;
                }
                case Op::I64_LOAD16_U: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = unpack('v', $bytes, $addr)[1]; break;
                }
                case Op::I64_LOAD32_S: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                        $stack[$sp++] = unpack('V', $bytes, $addr)[1] << 32 >> 32; break;
                }
                case Op::I64_LOAD32_U: {
                    $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $code[$ip++];
                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $stack[$sp++] = unpack('V', $bytes, $addr)[1]; break;
                }
                case Op::F32_LOAD: { $off=$code[$ip++]; $addr=(((int)$stack[--$sp])&0xFFFFFFFF)+$off; if($addr<0||$addr+4>$blimit) throw Trap::outOfBoundsMemoryAccess(); $bits=unpack('V',$bytes,$addr)[1]; if(($bits&0x7FFFFFFF)>0x7F800000){$stack[$sp++]=$bits<<32>>32;}else{$stack[$sp++]=unpack('f',$bytes,$addr)[1];} break; }
                case Op::F64_LOAD: { $off=$code[$ip++]; $addr=(((int)$stack[--$sp])&0xFFFFFFFF)+$off; if($addr<0||$addr+8>$blimit) throw Trap::outOfBoundsMemoryAccess(); $stack[$sp++]=unpack('d',$bytes,$addr)[1]; break; }
                case Op::I32_STORE: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; $bytes[$addr+1] = $chrStr[($v >> 8) & 0xFF];
                    $bytes[$addr+2] = $chrStr[($v >> 16) & 0xFF]; $bytes[$addr+3] = $chrStr[($v >> 24) & 0xFF]; break;
                }
                case Op::I32_STORE8: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; break;
                }
                case Op::I32_STORE16: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; $bytes[$addr+1] = $chrStr[($v >> 8) & 0xFF]; break;
                }
                case Op::I64_STORE: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 8 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $p=pack('VV',$v,$v>>32); $bytes[$addr]=$p[0];$bytes[$addr+1]=$p[1];$bytes[$addr+2]=$p[2];$bytes[$addr+3]=$p[3];$bytes[$addr+4]=$p[4];$bytes[$addr+5]=$p[5];$bytes[$addr+6]=$p[6];$bytes[$addr+7]=$p[7]; break;
                }
                case Op::I64_STORE8: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 1 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; break;
                }
                case Op::I64_STORE16: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 2 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; $bytes[$addr+1] = $chrStr[($v >> 8) & 0xFF]; break;
                }
                case Op::I64_STORE32: {
                    $off = $code[$ip++]; $v = (int)$stack[--$sp]; $addr = (((int)$stack[--$sp]) & 0xFFFFFFFF) + $off;
                    if ($addr < 0 || $addr + 4 > $blimit) throw Trap::outOfBoundsMemoryAccess();
                    $bytes[$addr] = $chrStr[$v & 0xFF]; $bytes[$addr+1] = $chrStr[($v >> 8) & 0xFF];
                    $bytes[$addr+2] = $chrStr[($v >> 16) & 0xFF]; $bytes[$addr+3] = $chrStr[($v >> 24) & 0xFF]; break;
                }
                case Op::F32_STORE: { $off=$code[$ip++]; $v=$stack[--$sp]; $addr=(((int)$stack[--$sp])&0xFFFFFFFF)+$off; if($addr<0||$addr+4>$blimit) throw Trap::outOfBoundsMemoryAccess(); $bits=is_int($v)?$v:(unpack('V',pack('f',(float)$v))[1]); $bytes[$addr]=$chrStr[$bits&0xFF];$bytes[$addr+1]=$chrStr[($bits>>8)&0xFF];$bytes[$addr+2]=$chrStr[($bits>>16)&0xFF];$bytes[$addr+3]=$chrStr[($bits>>24)&0xFF]; break; }
                case Op::F64_STORE: { $off=$code[$ip++]; $v=(float)$stack[--$sp]; $addr=(((int)$stack[--$sp])&0xFFFFFFFF)+$off; if($addr<0||$addr+8>$blimit) throw Trap::outOfBoundsMemoryAccess(); $p=pack('d',$v); $bytes[$addr]=$p[0];$bytes[$addr+1]=$p[1];$bytes[$addr+2]=$p[2];$bytes[$addr+3]=$p[3];$bytes[$addr+4]=$p[4];$bytes[$addr+5]=$p[5];$bytes[$addr+6]=$p[6];$bytes[$addr+7]=$p[7]; break; }

                // ---- Table ----
                case Op::TABLE_SIZE: {
                    $tIdx = $code[$ip++];
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[$sp++] = $table->size();
                    break;
                }
                case Op::TABLE_GROW: {
                    $tIdx = $code[$ip++];
                    $n    = (int)$stack[--$sp];
                    $val  = $stack[--$sp];
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[$sp++] = $table->grow($n, $val);
                    break;
                }
                case Op::TABLE_GET: {
                    $tIdx = $code[$ip++];
                    $idx  = (int)$stack[--$sp];
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[$sp++] = $table->get($idx);
                    break;
                }
                case Op::TABLE_SET: {
                    $tIdx = $code[$ip++];
                    $val  = $stack[--$sp];
                    $idx  = (int)$stack[--$sp];
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $table->set($idx, $val);
                    break;
                }
                case Op::TABLE_FILL: {
                    $tIdx = $code[$ip++];
                    $n    = (int)$stack[--$sp];
                    $val  = $stack[--$sp];
                    $i    = (int)$stack[--$sp];
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    if ($i < 0 || $n < 0 || ($i & 0xFFFFFFFF) + ($n & 0xFFFFFFFF) > $table->size()) {
                        throw Trap::outOfBoundsTableAccess();
                    }
                    for ($k = 0; $k < $n; $k++) $table->set($i + $k, $val);
                    break;
                }
                case Op::TABLE_COPY: {
                    $dIdx = $code[$ip++];
                    $sIdx = $code[$ip++];
                    $n    = (int)$stack[--$sp];
                    $s    = (int)$stack[--$sp];
                    $d    = (int)$stack[--$sp];
                    $su = $s & 0xFFFFFFFF; $du = $d & 0xFFFFFFFF; $nu = $n & 0xFFFFFFFF;
                    $dTable = $tables[$dIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $sTable = $tables[$sIdx] ?? throw Trap::outOfBoundsTableAccess();
                    if ($su + $nu > $sTable->size() || $du + $nu > $dTable->size()) {
                        throw Trap::outOfBoundsTableAccess();
                    }
                    if ($nu === 0) break;
                    if ($du <= $su) {
                        for ($k = 0; $k < $nu; $k++) $dTable->set($du + $k, $sTable->get($su + $k));
                    } else {
                        for ($k = $nu - 1; $k >= 0; $k--) $dTable->set($du + $k, $sTable->get($su + $k));
                    }
                    break;
                }
                case Op::TABLE_INIT: {
                    $tIdx = $code[$ip++];
                    $eIdx = $code[$ip++];
                    $n    = (int)$stack[--$sp];
                    $s    = (int)$stack[--$sp];
                    $d    = (int)$stack[--$sp];
                    $su = $s & 0xFFFFFFFF; $du = $d & 0xFFFFFFFF; $nu = $n & 0xFFFFFFFF;
                    $table = $tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $elem  = $mod->elements[$eIdx] ?? null;
                    $funcIndices = ($elem && !empty($elem['funcIndices'])) ? $elem['funcIndices'] : [];
                    if ($su + $nu > count($funcIndices) || $du + $nu > $table->size()) {
                        throw Trap::outOfBoundsTableAccess();
                    }
                    for ($k = 0; $k < $nu; $k++) $table->set($du + $k, $funcIndices[$su + $k] ?? null);
                    break;
                }
                case Op::ELEM_DROP: {
                    $eIdx = $code[$ip++];
                    if (isset($mod->elements[$eIdx])) {
                        $mod->elements[$eIdx]['funcIndices'] = [];
                    }
                    break;
                }
                // ---- References ----
                case Op::REF_NULL:
                    $stack[$sp++] = null;
                    break;
                case Op::REF_FUNC: {
                    $fIdx = $code[$ip++];
                    $stack[$sp++] = $fIdx;
                    break;
                }
                case Op::REF_IS_NULL:
                    $stack[$sp-1] = ($stack[$sp-1] === null) ? 1 : 0;
                    break;
                case Op::REF_AS_NON_NULL: {
                    $val = $stack[--$sp];
                    if ($val === null) throw new Trap('null dereference');
                    $stack[$sp++] = $val;
                    break;
                }
                // ---- Memory bulk operations ----
                case Op::MEMORY_FILL: {
                    $n   = (int)$stack[--$sp];
                    $val = (int)$stack[--$sp];
                    $d   = (int)$stack[--$sp];
                    $mem0->fill($d, $val & 0xFF, $n);
                    break;
                }
                case Op::MEMORY_COPY: {
                    $n = (int)$stack[--$sp];
                    $s = (int)$stack[--$sp];
                    $d = (int)$stack[--$sp];
                    $mem0->copy($d, $s, $n);
                    break;
                }
                case Op::MEMORY_INIT: {
                    $segIdx = $code[$ip++];
                    $n = (int)$stack[--$sp];
                    $s = (int)$stack[--$sp];
                    $d = (int)$stack[--$sp];
                    $data = $mod->dataSegments[$segIdx]['bytes'] ?? '';
                    $mem0->initFromData($d, $data, $s, $n);
                    break;
                }
                case Op::DATA_DROP: {
                    $dIdx = $code[$ip++];
                    if (isset($mod->dataSegments[$dIdx])) {
                        $mod->dataSegments[$dIdx]['bytes'] = '';
                    }
                    break;
                }

                default:
                    break; // unknown/future instructions silently skipped
            }
        } // end inner while ($ip < $len)

        // Current frame complete — copy results and handle frame stack
        $retStart = $retBase >= 0 ? $retBase : ($retCount > 0 ? (($t=$sp-$retCount)>0?$t:0) : $sp);
        $nResults = $retCount;
        $retBase  = -1;
        if ($fsp === 0) {
            if ($nResults === 0) return [];
            if ($nResults === 1) return [$stack[$retStart]];
            return array_slice($stack, $retStart, $nResults);
        }
        // Pop caller frame — 6 slots: [code, ip, len, retCount, lbase, newLbase]
        $fsp -= 6;
        $code=$frameData[$fsp]; $ip=$frameData[$fsp+1]; $len=$frameData[$fsp+2]; $retCount=$frameData[$fsp+3];
        $lbase=$frameData[$fsp+4]; $sp=$frameData[$fsp+5];
        if ($nResults === 1) { $stack[$sp++] = $stack[$retStart]; }
        elseif ($nResults > 1) { for ($__i = 0; $__i < $nResults; $__i++) $stack[$sp++] = $stack[$retStart + $__i]; }
        } // end outer while (true)
    }

    // -------------------------------------------------------------------------
    // Numeric helpers
    // -------------------------------------------------------------------------

    private static function asF32(mixed $v): float { return is_int($v) ? (float)unpack('f',pack('V',$v&0xFFFFFFFF))[1] : (float)$v; }

    private static function clz32(int $a): int { return 31-(int)floor(log($a,2)); }
    private static function clz64(int $a): int
    {
        $n=0; $mask=PHP_INT_MIN;
        while(($a&$mask)===0){$n++;$mask=self::shr64u($mask,1);if($n>=64)break;}
        return $n;
    }
    private static function ctz(int $a): int { $n=0; while(($a&1)===0){$a>>=1;$n++;} return $n; }

    private static function shr64u(int $v, int $shift): int
    {
        if ($shift===0) return $v;
        if ($shift>=64) return 0;
        if ($v>=0) return $v>>$shift;
        if ($shift>=63) return 1;
        $hi=($v>>32)&0xFFFFFFFF; $lo=$v&0xFFFFFFFF;
        if ($shift<32) {
            $newHi=($hi>>$shift)&((1<<(32-$shift))-1);
            $newLo=(($lo>>$shift)|($hi<<(32-$shift)))&0xFFFFFFFF;
            return ($newHi<<32)|$newLo;
        }
        return ($hi>>($shift-32))&((1<<(64-$shift))-1);
    }

    private static function u64cmp(int $a, int $b): int
    {
        if ($a===$b) return 0;
        $as=($a>>63)&1; $bs=($b>>63)&1;
        if ($as!==$bs) return $as>$bs?1:-1;
        return $a<=>$b;
    }

    private static function u64toFloat(int $v): float
    {
        if ($v>=0) return (float)$v;
        return (float)(($v>>1)&PHP_INT_MAX)*2.0+($v&1);
    }

    private static function u64ToGmp(int $a): \GMP
    {
        return $a >= 0 ? gmp_init($a) : gmp_add(gmp_init($a), gmp_pow(2, 64));
    }

    private static function gmpToU64(\GMP $v): int
    {
        if (gmp_cmp($v, gmp_pow(2, 63)) >= 0) {
            $v = gmp_sub($v, gmp_pow(2, 64));
        }
        return gmp_intval($v);
    }

    private static function int64Add(int $a, int $b): int
    {
        // Pure-int 64-bit wrapping add (no GMP): split into 32-bit halves.
        $lo = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
        $hi = (($a >> 32) & 0xFFFFFFFF) + (($b >> 32) & 0xFFFFFFFF) + ($lo >> 32 & 1);
        return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
    }

    private static function int64Sub(int $a, int $b): int
    {
        // Pure-int 64-bit wrapping sub (no GMP): split into 32-bit halves.
        $lo = ($a & 0xFFFFFFFF) - ($b & 0xFFFFFFFF);
        $borrow = ($lo < 0) ? 1 : 0;
        $hi = (($a >> 32) & 0xFFFFFFFF) - (($b >> 32) & 0xFFFFFFFF) - $borrow;
        return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
    }

    private static function int64Mul(int $a, int $b): int
    {
        // Pure-int 64-bit wrapping multiply via 16-bit chunk decomposition (no GMP).
        $a0=$a&0xFFFF; $a1=($a>>16)&0xFFFF; $a2=($a>>32)&0xFFFF; $a3=($a>>48)&0xFFFF;
        $b0=$b&0xFFFF; $b1=($b>>16)&0xFFFF; $b2=($b>>32)&0xFFFF; $b3=($b>>48)&0xFFFF;
        $c0 = $a0*$b0;
        $c1 = $a1*$b0 + $a0*$b1 + ($c0>>16);
        $c2 = $a2*$b0 + $a1*$b1 + $a0*$b2 + ($c1>>16);
        $c3 = $a3*$b0 + $a2*$b1 + $a1*$b2 + $a0*$b3 + ($c2>>16);
        return (($c3&0xFFFF)<<48)|(($c2&0xFFFF)<<32)|(($c1&0xFFFF)<<16)|($c0&0xFFFF);
    }

    private static function u64div(int $a, int $b): int
    {
        if ($a >= 0 && $b > 0) return intdiv($a, $b);
        return self::gmpToU64(gmp_div_q(self::u64ToGmp($a), self::u64ToGmp($b)));
    }

    private static function u64rem(int $a, int $b): int
    {
        if ($a >= 0 && $b > 0) return $a % $b;
        return self::gmpToU64(gmp_mod(self::u64ToGmp($a), self::u64ToGmp($b)));
    }

    private static function fdiv(float $a, float $b): float
    {
        if ($b == 0.0) {
            if ($a == 0.0 || is_nan($a)) return NAN;
            $neg = self::isNegZero($b) ^ ($a < 0);
            return $neg ? -INF : INF;
        }
        return $a / $b;
    }

    private static function isNegZero(float $v): bool
    {
        $bytes = unpack('C8', pack('d', $v));
        return ($bytes[8] & 0x80) !== 0;
    }

    private static function fmin(float $a, float $b): float
    {
        if (is_nan($a)||is_nan($b)) return NAN;
        if ($a===0.0&&$b===0.0) return (self::isNegZero($a)||self::isNegZero($b))?-0.0:0.0;
        return min($a,$b);
    }

    private static function fmax(float $a, float $b): float
    {
        if (is_nan($a)||is_nan($b)) return NAN;
        if ($a===0.0&&$b===0.0) return (!self::isNegZero($a)||!self::isNegZero($b))?0.0:-0.0;
        return max($a,$b);
    }

    private static function nearest(float $a): float
    {
        if (!is_finite($a)) return $a;
        $floor=floor($a); $ceil=ceil($a); $diff=$a-$floor;
        if ($diff<0.5) return $floor;
        if ($diff>0.5) return $ceil;
        return (fmod($floor,2.0)===0.0)?$floor:$ceil;
    }

    private static function copysign(float $a, float $b): float
    {
        $bytes = unpack('C8', pack('d', $b));
        $neg   = ($bytes[8] & 0x80) !== 0;
        return $neg ? -abs($a) : abs($a);
    }

    private static function truncF2I32s(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a>=2147483648.0||$a<=-2147483649.0) throw Trap::integerOverflow();
        return WasmValue::mask32((int)$a);
    }

    private static function truncF2I32u(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a>=4294967296.0||$a<=-1.0) throw Trap::integerOverflow();
        return WasmValue::mask32((int)$a);
    }

    private static function truncF2I64s(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a>=9.223372036854776E+18||$a<-9.223372036854776E+18) throw Trap::integerOverflow();
        return (int)$a;
    }

    private static function truncF2I64u(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a<=-1.0||$a>=1.8446744073709552E+19) throw Trap::integerOverflow();
        if ($a>=9.223372036854776E+18) return (int)($a-9.223372036854776E+18)|PHP_INT_MIN;
        return (int)$a;
    }

    private static function truncSatI32s(float $a): int
    {
        if (is_nan($a)) return 0;
        if ($a>=2147483648.0) return 2147483647;
        if ($a<-2147483648.0) return -2147483648;
        return WasmValue::mask32((int)$a);
    }

    private static function truncSatI32u(float $a): int
    {
        if (is_nan($a)||$a<0.0) return 0;
        if ($a>=4294967296.0) return WasmValue::mask32(-1);
        return WasmValue::mask32((int)$a);
    }

    private static function truncSatI64s(float $a): int
    {
        if (is_nan($a)) return 0;
        if ($a>=9.223372036854776E+18) return PHP_INT_MAX;
        if ($a<-9.223372036854776E+18) return PHP_INT_MIN;
        return (int)$a;
    }

    private static function truncSatI64u(float $a): int
    {
        if (is_nan($a)||$a<0.0) return 0;
        if ($a>=1.8446744073709552E+19) return -1;
        if ($a>=9.223372036854776E+18) return (int)($a-9.223372036854776E+18)|PHP_INT_MIN;
        return (int)$a;
    }
}

/** @internal Signals a tail call (return_call / return_call_indirect) for TCO */
final class TailCallSignal extends \Exception
{
    /** @param (int|float|null)[] $args raw values */
    public function __construct(
        public readonly int   $funcIdx,
        public readonly array $args,
    ) {
        parent::__construct();
    }
}
