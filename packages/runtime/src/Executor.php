<?php

declare(strict_types=1);

namespace WasmRuntime;

/**
 * Iterative WebAssembly interpreter with a label stack.
 *
 * Instruction format (flat array produced by Wat\Parser):
 *   ['opcode', ...immediates]
 *
 * Control instructions carry pre-computed IP targets:
 *   ['block', ?blockType, endIp]           endIp  = IP of matching 'end'
 *   ['loop',  ?blockType, contIp, endIp]   contIp = first body instr IP
 *   ['if',    ?blockType, elseIp, endIp]   elseIp == endIp → no else branch
 *   ['else',  endIp]
 *   ['end']
 *
 * Label stack entry: [type, contIp, stackHeight, resultCount]
 *   type:        'block' | 'loop'
 *   contIp:      for block/if  → endIp + 1 (after 'end')
 *                for loop      → first body IP
 *   stackHeight: stack.count at block entry (restored on branch)
 *   resultCount: 0 | 1  (loops always 0 in MVP)
 */
final class Executor
{
    private const MAX_CALL_DEPTH = 1000;

    /** @var callable[] absIndex => PHP callable for host functions */
    private array $hostFuncs = [];
    private int   $callDepth = 0;

    public function __construct(private readonly Instance $instance) {}

    public function registerHostFunc(int $funcIdx, callable $fn): void
    {
        $this->hostFuncs[$funcIdx] = $fn;
    }

    /**
     * @param  WasmValue[] $args
     * @return WasmValue[]
     */
    public function invoke(int $funcIdx, array $args): array
    {
        if (++$this->callDepth > self::MAX_CALL_DEPTH) {
            $this->callDepth--;
            throw Trap::callStackExhausted();
        }
        try {
            return $this->callFunction($funcIdx, $args);
        } finally {
            $this->callDepth--;
        }
    }

    /** @return WasmValue[] */
    private function callFunction(int $funcIdx, array $args): array
    {
        $mod = $this->instance->module;

        if (isset($this->hostFuncs[$funcIdx])) {
            $r = ($this->hostFuncs[$funcIdx])($args);
            return is_array($r) ? $r : ($r !== null ? [$r] : []);
        }

        $localIdx = $funcIdx - $mod->importedFuncCount;
        if ($localIdx < 0 || $localIdx >= count($mod->funcBodies)) {
            throw new Trap("Invalid function index: $funcIdx");
        }

        $body = $mod->funcBodies[$localIdx];
        $ft   = $mod->funcType($funcIdx);

        $locals = [];
        foreach ($args as $a) {
            $locals[] = $a->value;
        }
        foreach ($body['locals'] as $lt) {
            $locals[] = match ($lt) {
                ValType::I32, ValType::I64 => 0,
                ValType::F32, ValType::F64 => 0.0,
                default => 0,
            };
        }

        $rawResults = $this->run($body['code'], $locals, $ft);

        $out = [];
        foreach ($ft->results as $i => $rtype) {
            $v     = $rawResults[$i] ?? 0;
            $out[] = match ($rtype) {
                ValType::I32 => WasmValue::i32((int)$v),
                ValType::I64 => WasmValue::i64((int)$v),
                ValType::F32 => WasmValue::f32((float)$v),
                ValType::F64 => WasmValue::f64((float)$v),
                default      => WasmValue::i32((int)$v),
            };
        }
        return $out;
    }

    /**
     * Main interpreter loop (iterative, label-stack based).
     * @return (int|float)[]  raw result values
     */
    private function run(array $code, array $locals, FuncType $ft): array
    {
        $stack      = [];
        $labelStack = [];   // [[type, contIp, stackHeight, resultCount]]
        $ip         = 0;
        $len        = count($code);
        $retCount   = count($ft->results);

        try {
        while ($ip < $len) {
            $instr = $code[$ip++];
            $op    = $instr[0];

            switch ($op) {

                // ---- Control ----
                case 'unreachable':
                    throw Trap::unreachable();

                case 'nop':
                    break;

                case 'block': {
                    $blockType    = $instr[1];
                    $endIp        = $instr[2];
                    $resultCount  = ($blockType !== null) ? 1 : 0;
                    $labelStack[] = ['block', $endIp + 1, count($stack), $resultCount];
                    break;
                }

                case 'loop': {
                    $contIp       = $instr[2]; // first body instruction IP
                    $labelStack[] = ['loop', $contIp, count($stack), 0];
                    break;
                }

                case 'if': {
                    $blockType   = $instr[1];
                    $elseIp      = $instr[2];
                    $endIp       = $instr[3];
                    $hasElse     = ($elseIp !== $endIp);
                    $resultCount = ($blockType !== null) ? 1 : 0;
                    $cond        = (int)array_pop($stack);

                    if ($cond !== 0) {
                        $labelStack[] = ['block', $endIp + 1, count($stack), $resultCount];
                    } else {
                        if ($hasElse) {
                            $ip           = $elseIp + 1; // skip 'else' instruction
                            $labelStack[] = ['block', $endIp + 1, count($stack), $resultCount];
                        } else {
                            $ip = $endIp + 1; // skip 'end', no label needed
                        }
                    }
                    break;
                }

                case 'else': {
                    // Reached while executing then-branch → jump past else+end
                    $endIp = $instr[1];
                    array_pop($labelStack);
                    $ip = $endIp + 1;
                    break;
                }

                case 'end': {
                    if (!empty($labelStack)) {
                        array_pop($labelStack);
                    }
                    break;
                }

                case 'return': {
                    $n = count($stack);
                    return array_slice($stack, max(0, $n - $retCount));
                }

                case 'br': {
                    $depth      = (int)$instr[1];
                    $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    break;
                }

                case 'br_if': {
                    $depth = (int)$instr[1];
                    $cond  = (int)array_pop($stack);
                    if ($cond !== 0) {
                        $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    }
                    break;
                }

                case 'br_table': {
                    $cnt     = count($instr) - 1;
                    $targets = array_slice($instr, 1, $cnt - 1);
                    $default = $instr[$cnt];
                    $idx     = (int)array_pop($stack);
                    $depth   = (int)(($idx >= 0 && $idx < count($targets)) ? $targets[$idx] : $default);
                    $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    break;
                }

                case 'call': {
                    $fIdx = $instr[1];
                    $cft  = $this->instance->module->funcType($fIdx);
                    $pc   = count($cft->params);
                    $reversed = [];
                    for ($j = 0; $j < $pc; $j++) {
                        $reversed[] = $this->makeVal($cft->params[$pc - 1 - $j], array_pop($stack));
                    }
                    $cargs = array_reverse($reversed);
                    foreach ($this->invoke($fIdx, $cargs) as $r) {
                        $stack[] = $r->value;
                    }
                    break;
                }

                case 'call_indirect': {
                    $typeIdx  = $instr[1];
                    $tableIdx = $instr[2] ?? 0;
                    $elemIdx  = (int)array_pop($stack);
                    $cft      = $this->instance->module->types[$typeIdx];
                    $pc       = count($cft->params);
                    $reversed = [];
                    for ($j = 0; $j < $pc; $j++) {
                        $reversed[] = $this->makeVal($cft->params[$pc - 1 - $j], array_pop($stack));
                    }
                    $cargs = array_reverse($reversed);
                    $table = $this->instance->tables[$tableIdx]
                        ?? throw Trap::outOfBoundsTableAccess();
                    $fIdx  = $table->get($elemIdx);
                    if ($fIdx === null) throw Trap::uninitializedElement();
                    if (!$cft->equals($this->instance->module->funcType($fIdx)))
                        throw Trap::indirectCallTypeMismatch();
                    foreach ($this->invoke($fIdx, $cargs) as $r) {
                        $stack[] = $r->value;
                    }
                    break;
                }

                // ---- Parametric ----
                case 'drop':   array_pop($stack); break;
                case 'select': {
                    $c = (int)array_pop($stack);
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $c !== 0 ? $a : $b;
                    break;
                }

                // ---- Variable ----
                case 'local.get':  $stack[] = $locals[(int)$instr[1]]; break;
                case 'local.set':  $locals[(int)$instr[1]] = array_pop($stack); break;
                case 'local.tee':  $locals[(int)$instr[1]] = end($stack); break;
                case 'global.get': $stack[] = $this->instance->globals[(int)$instr[1]]; break;
                case 'global.set': $this->instance->globals[(int)$instr[1]] = array_pop($stack); break;

                // ---- Constants ----
                case 'i32.const': $stack[] = (int)$instr[1]; break;
                case 'i64.const': $stack[] = (int)$instr[1]; break;
                case 'f32.const': $stack[] = (float)$instr[1]; break;
                case 'f64.const': $stack[] = (float)$instr[1]; break;

                // ---- i32 arithmetic ----
                case 'i32.add': { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a+$b); break; }
                case 'i32.sub': { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a-$b); break; }
                case 'i32.mul': { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a*$b); break; }
                case 'i32.div_s': {
                    [$a,$b]=self::p2i($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===-2147483648 && $b===-1) throw Trap::integerOverflow();
                    $stack[]=WasmValue::mask32(intdiv($a,$b)); break;
                }
                case 'i32.div_u': {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32((int)($a/$b)); break;
                }
                case 'i32.rem_s': {
                    [$a,$b]=self::p2i($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32($a%$b); break;
                }
                case 'i32.rem_u': {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32($a%$b); break;
                }
                case 'i32.and':   { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a&$b); break; }
                case 'i32.or':    { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a|$b); break; }
                case 'i32.xor':   { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a^$b); break; }
                case 'i32.shl':   { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a<<($b&31)); break; }
                case 'i32.shr_s': { [$a,$b]=self::p2i($stack); $stack[]=WasmValue::mask32($a>>($b&31)); break; }
                case 'i32.shr_u': {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32($a>>($b&31)); break;
                }
                case 'i32.rotl': {
                    $b=WasmValue::u32((int)array_pop($stack))&31; $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32(($a<<$b)|($a>>(32-$b))); break;
                }
                case 'i32.rotr': {
                    $b=WasmValue::u32((int)array_pop($stack))&31; $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32(($a>>$b)|($a<<(32-$b))); break;
                }
                case 'i32.clz':    { $a=WasmValue::u32((int)array_pop($stack)); $stack[]=$a===0?32:self::clz32($a); break; }
                case 'i32.ctz':    { $a=WasmValue::u32((int)array_pop($stack)); $stack[]=$a===0?32:self::ctz($a); break; }
                case 'i32.popcnt': { $a=WasmValue::u32((int)array_pop($stack)); $n=0; while($a){$n+=$a&1;$a>>=1;} $stack[]=$n; break; }
                case 'i32.eqz':    $stack[]=((int)array_pop($stack)===0)?1:0; break;
                case 'i32.eq':     { [$a,$b]=self::p2i($stack); $stack[]=($a===$b)?1:0; break; }
                case 'i32.ne':     { [$a,$b]=self::p2i($stack); $stack[]=($a!==$b)?1:0; break; }
                case 'i32.lt_s':   { [$a,$b]=self::p2i($stack); $stack[]=($a<$b)?1:0; break; }
                case 'i32.lt_u':   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a<$b)?1:0; break; }
                case 'i32.gt_s':   { [$a,$b]=self::p2i($stack); $stack[]=($a>$b)?1:0; break; }
                case 'i32.gt_u':   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a>$b)?1:0; break; }
                case 'i32.le_s':   { [$a,$b]=self::p2i($stack); $stack[]=($a<=$b)?1:0; break; }
                case 'i32.le_u':   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a<=$b)?1:0; break; }
                case 'i32.ge_s':   { [$a,$b]=self::p2i($stack); $stack[]=($a>=$b)?1:0; break; }
                case 'i32.ge_u':   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a>=$b)?1:0; break; }

                // ---- i64 arithmetic ----
                case 'i64.add': { [$a,$b]=self::p2i($stack); $stack[]=self::int64Add($a,$b); break; }
                case 'i64.sub': { [$a,$b]=self::p2i($stack); $stack[]=self::int64Sub($a,$b); break; }
                case 'i64.mul': { [$a,$b]=self::p2i($stack); $stack[]=self::int64Mul($a,$b); break; }
                case 'i64.div_s': {
                    [$a,$b]=self::p2i($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===PHP_INT_MIN && $b===-1) throw Trap::integerOverflow();
                    $stack[]=intdiv($a,$b); break;
                }
                case 'i64.div_u': { [$a,$b]=self::p2i($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=self::u64div($a,$b); break; }
                case 'i64.rem_s': { [$a,$b]=self::p2i($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=$a%$b; break; }
                case 'i64.rem_u': { [$a,$b]=self::p2i($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=self::u64rem($a,$b); break; }
                case 'i64.and':   { [$a,$b]=self::p2i($stack); $stack[]=$a&$b; break; }
                case 'i64.or':    { [$a,$b]=self::p2i($stack); $stack[]=$a|$b; break; }
                case 'i64.xor':   { [$a,$b]=self::p2i($stack); $stack[]=$a^$b; break; }
                case 'i64.shl':   { [$a,$b]=self::p2i($stack); $stack[]=$a<<($b&63); break; }
                case 'i64.shr_s': { [$a,$b]=self::p2i($stack); $stack[]=$a>>($b&63); break; }
                case 'i64.shr_u': { [$a,$b]=self::p2i($stack); $stack[]=self::shr64u($a,$b&63); break; }
                case 'i64.rotl':  { [$a,$b]=self::p2i($stack); $b&=63; $stack[]=($a<<$b)|self::shr64u($a,64-$b); break; }
                case 'i64.rotr':  { [$a,$b]=self::p2i($stack); $b&=63; $stack[]=self::shr64u($a,$b)|($a<<(64-$b)); break; }
                case 'i64.clz':   { $a=(int)array_pop($stack); $stack[]=$a===0?64:self::clz64($a); break; }
                case 'i64.ctz':   { $a=(int)array_pop($stack); $stack[]=$a===0?64:self::ctz($a); break; }
                case 'i64.popcnt':{ $a=(int)array_pop($stack); $n=0; for($b=0;$b<64;$b++){if(($a>>$b)&1)$n++;} $stack[]=$n; break; }
                case 'i64.eqz':   $stack[]=((int)array_pop($stack)===0)?1:0; break;
                case 'i64.eq':    { [$a,$b]=self::p2i($stack); $stack[]=($a===$b)?1:0; break; }
                case 'i64.ne':    { [$a,$b]=self::p2i($stack); $stack[]=($a!==$b)?1:0; break; }
                case 'i64.lt_s':  { [$a,$b]=self::p2i($stack); $stack[]=($a<$b)?1:0; break; }
                case 'i64.lt_u':  { [$a,$b]=self::p2i($stack); $stack[]=self::u64cmp($a,$b)<0?1:0; break; }
                case 'i64.gt_s':  { [$a,$b]=self::p2i($stack); $stack[]=($a>$b)?1:0; break; }
                case 'i64.gt_u':  { [$a,$b]=self::p2i($stack); $stack[]=self::u64cmp($a,$b)>0?1:0; break; }
                case 'i64.le_s':  { [$a,$b]=self::p2i($stack); $stack[]=($a<=$b)?1:0; break; }
                case 'i64.le_u':  { [$a,$b]=self::p2i($stack); $stack[]=self::u64cmp($a,$b)<=0?1:0; break; }
                case 'i64.ge_s':  { [$a,$b]=self::p2i($stack); $stack[]=($a>=$b)?1:0; break; }
                case 'i64.ge_u':  { [$a,$b]=self::p2i($stack); $stack[]=self::u64cmp($a,$b)>=0?1:0; break; }

                // ---- f32 arithmetic ----
                case 'f32.add':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32($a+$b); break; }
                case 'f32.sub':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32($a-$b); break; }
                case 'f32.mul':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32($a*$b); break; }
                case 'f32.div':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32($a/$b); break; }
                case 'f32.min':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32(self::fmin($a,$b)); break; }
                case 'f32.max':     { [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32(self::fmax($a,$b)); break; }
                case 'f32.abs':     { $stack[]=WasmValue::canonF32(abs((float)array_pop($stack))); break; }
                case 'f32.neg':     { $stack[]=WasmValue::canonF32(-(float)array_pop($stack)); break; }
                case 'f32.sqrt':    { $stack[]=WasmValue::canonF32(sqrt((float)array_pop($stack))); break; }
                case 'f32.ceil':    { $stack[]=WasmValue::canonF32(ceil((float)array_pop($stack))); break; }
                case 'f32.floor':   { $stack[]=WasmValue::canonF32(floor((float)array_pop($stack))); break; }
                case 'f32.trunc':   { $a=(float)array_pop($stack); $stack[]=WasmValue::canonF32($a>=0?floor($a):ceil($a)); break; }
                case 'f32.nearest': { $stack[]=WasmValue::canonF32(self::nearest((float)array_pop($stack))); break; }
                case 'f32.copysign':{ [$a,$b]=self::p2f($stack); $stack[]=WasmValue::canonF32(self::copysign($a,$b)); break; }
                case 'f32.eq':  { [$a,$b]=self::p2f($stack); $stack[]=($a===$b)?1:0; break; }
                case 'f32.ne':  { [$a,$b]=self::p2f($stack); $stack[]=($a!==$b)?1:0; break; }
                case 'f32.lt':  { [$a,$b]=self::p2f($stack); $stack[]=($a<$b)?1:0; break; }
                case 'f32.gt':  { [$a,$b]=self::p2f($stack); $stack[]=($a>$b)?1:0; break; }
                case 'f32.le':  { [$a,$b]=self::p2f($stack); $stack[]=($a<=$b)?1:0; break; }
                case 'f32.ge':  { [$a,$b]=self::p2f($stack); $stack[]=($a>=$b)?1:0; break; }

                // ---- f64 arithmetic ----
                case 'f64.add':     { [$a,$b]=self::p2f($stack); $stack[]=$a+$b; break; }
                case 'f64.sub':     { [$a,$b]=self::p2f($stack); $stack[]=$a-$b; break; }
                case 'f64.mul':     { [$a,$b]=self::p2f($stack); $stack[]=$a*$b; break; }
                case 'f64.div':     { [$a,$b]=self::p2f($stack); $stack[]=$a/$b; break; }
                case 'f64.min':     { [$a,$b]=self::p2f($stack); $stack[]=self::fmin($a,$b); break; }
                case 'f64.max':     { [$a,$b]=self::p2f($stack); $stack[]=self::fmax($a,$b); break; }
                case 'f64.abs':     { $stack[]=abs((float)array_pop($stack)); break; }
                case 'f64.neg':     { $stack[]=-(float)array_pop($stack); break; }
                case 'f64.sqrt':    { $stack[]=sqrt((float)array_pop($stack)); break; }
                case 'f64.ceil':    { $stack[]=ceil((float)array_pop($stack)); break; }
                case 'f64.floor':   { $stack[]=floor((float)array_pop($stack)); break; }
                case 'f64.trunc':   { $a=(float)array_pop($stack); $stack[]=$a>=0?floor($a):ceil($a); break; }
                case 'f64.nearest': { $stack[]=self::nearest((float)array_pop($stack)); break; }
                case 'f64.copysign':{ [$a,$b]=self::p2f($stack); $stack[]=self::copysign($a,$b); break; }
                case 'f64.eq':  { [$a,$b]=self::p2f($stack); $stack[]=($a===$b)?1:0; break; }
                case 'f64.ne':  { [$a,$b]=self::p2f($stack); $stack[]=($a!==$b)?1:0; break; }
                case 'f64.lt':  { [$a,$b]=self::p2f($stack); $stack[]=($a<$b)?1:0; break; }
                case 'f64.gt':  { [$a,$b]=self::p2f($stack); $stack[]=($a>$b)?1:0; break; }
                case 'f64.le':  { [$a,$b]=self::p2f($stack); $stack[]=($a<=$b)?1:0; break; }
                case 'f64.ge':  { [$a,$b]=self::p2f($stack); $stack[]=($a>=$b)?1:0; break; }

                // ---- Conversions ----
                case 'i32.wrap_i64':       { $stack[]=WasmValue::mask32((int)array_pop($stack)); break; }
                case 'i32.trunc_f32_s':    { $stack[]=self::truncF2I32s((float)array_pop($stack)); break; }
                case 'i32.trunc_f32_u':    { $stack[]=self::truncF2I32u((float)array_pop($stack)); break; }
                case 'i32.trunc_f64_s':    { $stack[]=self::truncF2I32s((float)array_pop($stack)); break; }
                case 'i32.trunc_f64_u':    { $stack[]=self::truncF2I32u((float)array_pop($stack)); break; }
                case 'i32.trunc_sat_f32_s':{ $stack[]=self::truncSatI32s((float)array_pop($stack)); break; }
                case 'i32.trunc_sat_f32_u':{ $stack[]=self::truncSatI32u((float)array_pop($stack)); break; }
                case 'i32.trunc_sat_f64_s':{ $stack[]=self::truncSatI32s((float)array_pop($stack)); break; }
                case 'i32.trunc_sat_f64_u':{ $stack[]=self::truncSatI32u((float)array_pop($stack)); break; }
                case 'i64.extend_i32_s':   { $stack[]=WasmValue::mask32((int)array_pop($stack)); break; }
                case 'i64.extend_i32_u':   { $stack[]=WasmValue::u32((int)array_pop($stack)); break; }
                case 'i64.trunc_f32_s':    { $stack[]=self::truncF2I64s((float)array_pop($stack)); break; }
                case 'i64.trunc_f32_u':    { $stack[]=self::truncF2I64u((float)array_pop($stack)); break; }
                case 'i64.trunc_f64_s':    { $stack[]=self::truncF2I64s((float)array_pop($stack)); break; }
                case 'i64.trunc_f64_u':    { $stack[]=self::truncF2I64u((float)array_pop($stack)); break; }
                case 'i64.trunc_sat_f64_s':{ $stack[]=self::truncSatI64s((float)array_pop($stack)); break; }
                case 'i64.trunc_sat_f64_u':{ $stack[]=self::truncSatI64u((float)array_pop($stack)); break; }
                case 'i64.trunc_sat_f32_s':{ $stack[]=self::truncSatI64s((float)array_pop($stack)); break; }
                case 'i64.trunc_sat_f32_u':{ $stack[]=self::truncSatI64u((float)array_pop($stack)); break; }
                case 'f32.convert_i32_s':  { $stack[]=WasmValue::canonF32((float)(int)array_pop($stack)); break; }
                case 'f32.convert_i32_u':  { $stack[]=WasmValue::canonF32((float)WasmValue::u32((int)array_pop($stack))); break; }
                case 'f32.convert_i64_s':  { $stack[]=WasmValue::canonF32((float)(int)array_pop($stack)); break; }
                case 'f32.convert_i64_u':  { $stack[]=WasmValue::canonF32(self::u64toFloat((int)array_pop($stack))); break; }
                case 'f32.demote_f64':     { $stack[]=WasmValue::canonF32((float)array_pop($stack)); break; }
                case 'f64.convert_i32_s':  { $stack[]=(float)(int)array_pop($stack); break; }
                case 'f64.convert_i32_u':  { $stack[]=(float)WasmValue::u32((int)array_pop($stack)); break; }
                case 'f64.convert_i64_s':  { $stack[]=(float)(int)array_pop($stack); break; }
                case 'f64.convert_i64_u':  { $stack[]=self::u64toFloat((int)array_pop($stack)); break; }
                case 'f64.promote_f32':    { $stack[]=(float)array_pop($stack); break; }
                case 'i32.reinterpret_f32': {
                    $v=(float)array_pop($stack);
                    $stack[]=WasmValue::mask32(unpack('V',pack('f',$v))[1]); break;
                }
                case 'i64.reinterpret_f64': {
                    $v=(float)array_pop($stack); $p=pack('d',$v);
                    $lo=unpack('V',$p)[1]; $hi=unpack('V',substr($p,4))[1];
                    $stack[]=($hi<<32)|$lo; break;
                }
                case 'f32.reinterpret_i32': {
                    $v=(int)array_pop($stack);
                    $stack[]=WasmValue::canonF32(unpack('f',pack('V',$v&0xFFFFFFFF))[1]); break;
                }
                case 'f64.reinterpret_i64': {
                    $v=(int)array_pop($stack);
                    $lo=$v&0xFFFFFFFF; $hi=($v>>32)&0xFFFFFFFF;
                    $stack[]=unpack('d',pack('VV',$lo,$hi))[1]; break;
                }
                case 'i32.extend8_s':  { $v=(int)array_pop($stack)&0xFF;   $stack[]=WasmValue::mask32(($v&0x80)?$v|(-1<<8):$v); break; }
                case 'i32.extend16_s': { $v=(int)array_pop($stack)&0xFFFF; $stack[]=WasmValue::mask32(($v&0x8000)?$v|(-1<<16):$v); break; }
                case 'i64.extend8_s':  { $v=(int)array_pop($stack)&0xFF;   $stack[]=($v&0x80)?$v|(-1<<8):$v; break; }
                case 'i64.extend16_s': { $v=(int)array_pop($stack)&0xFFFF; $stack[]=($v&0x8000)?$v|(-1<<16):$v; break; }
                case 'i64.extend32_s': { $v=(int)array_pop($stack)&0xFFFFFFFF; $stack[]=($v&0x80000000)?$v|(-1<<32):$v; break; }

                // ---- Memory ----
                case 'memory.size': $stack[]=$this->instance->memories[0]->size(); break;
                case 'memory.grow': { $stack[]=$this->instance->memories[0]->grow((int)array_pop($stack)); break; }
                case 'i32.load':    { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI32($a+(int)$instr[1]); break; }
                case 'i64.load':    { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI64($a+(int)$instr[1]); break; }
                case 'f32.load':    { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadF32($a+(int)$instr[1]); break; }
                case 'f64.load':    { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadF64($a+(int)$instr[1]); break; }
                case 'i32.load8_s': { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI8s($a+(int)$instr[1]); break; }
                case 'i32.load8_u': { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI8u($a+(int)$instr[1]); break; }
                case 'i32.load16_s':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI16s($a+(int)$instr[1]); break; }
                case 'i32.load16_u':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI16u($a+(int)$instr[1]); break; }
                case 'i64.load8_s': { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI8s($a+(int)$instr[1]); break; }
                case 'i64.load8_u': { $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI8u($a+(int)$instr[1]); break; }
                case 'i64.load16_s':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI16s($a+(int)$instr[1]); break; }
                case 'i64.load16_u':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI16u($a+(int)$instr[1]); break; }
                case 'i64.load32_s':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadI32s($a+(int)$instr[1]); break; }
                case 'i64.load32_u':{ $a=(int)array_pop($stack); $stack[]=$this->instance->memories[0]->loadU32($a+(int)$instr[1]); break; }
                case 'i32.store':   { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI32($a+(int)$instr[1],$v); break; }
                case 'i64.store':   { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI64($a+(int)$instr[1],$v); break; }
                case 'f32.store':   { $v=(float)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeF32($a+(int)$instr[1],$v); break; }
                case 'f64.store':   { $v=(float)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeF64($a+(int)$instr[1],$v); break; }
                case 'i32.store8':  { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI8($a+(int)$instr[1],$v); break; }
                case 'i32.store16': { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI16($a+(int)$instr[1],$v); break; }
                case 'i64.store8':  { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI8($a+(int)$instr[1],$v); break; }
                case 'i64.store16': { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI16($a+(int)$instr[1],$v); break; }
                case 'i64.store32': { $v=(int)array_pop($stack); $a=(int)array_pop($stack); $this->instance->memories[0]->storeI32($a+(int)$instr[1],$v); break; }

                default:
                    break; // unknown/future instructions silently skipped
            }
        }
        } catch (EarlyReturn $e) {
            return $e->values;
        }

        $n = count($stack);
        return array_slice($stack, max(0, $n - $retCount));
    }

    // -------------------------------------------------------------------------
    // Branch logic
    // -------------------------------------------------------------------------

    /**
     * Execute a branch of depth $depth.
     * Mutates $stack in-place, mutates $ip by reference, returns new labelStack.
     */
    private function doBranch(
        int   $depth,
        array &$stack,
        array $labelStack,
        int   $retCount,
        int   &$ip,
    ): array {
        $lsCount   = count($labelStack);
        $targetIdx = $lsCount - 1 - $depth;

        if ($targetIdx < 0) {
            // Branch out of function — treat as return
            $n    = count($stack);
            $vals = array_slice($stack, max(0, $n - $retCount));
            throw new EarlyReturn($vals);
        }

        [$type, $contIp, $stackHeight, $resultCount] = $labelStack[$targetIdx];

        // Carry results over branch
        $n       = count($stack);
        $topVals = ($resultCount > 0 && $n >= $resultCount) ? array_slice($stack, $n - $resultCount) : [];
        $stack   = array_slice($stack, 0, $stackHeight);
        foreach ($topVals as $v) {
            $stack[] = $v;
        }

        $ip = $contIp;

        if ($type === 'loop') {
            // Continue loop: keep loop label, discard labels above
            return array_slice($labelStack, 0, $targetIdx + 1);
        } else {
            // Exit block: discard target label + everything above
            return array_slice($labelStack, 0, $targetIdx);
        }
    }

    // -------------------------------------------------------------------------
    // Numeric helpers
    // -------------------------------------------------------------------------

    private function makeVal(int $type, int|float $raw): WasmValue
    {
        return match ($type) {
            ValType::I32 => WasmValue::i32((int)$raw),
            ValType::I64 => WasmValue::i64((int)$raw),
            ValType::F32 => WasmValue::f32((float)$raw),
            ValType::F64 => WasmValue::f64((float)$raw),
            default      => WasmValue::i32((int)$raw),
        };
    }

    private static function p2i(array &$s): array { $b=(int)array_pop($s); $a=(int)array_pop($s); return [$a,$b]; }
    private static function p2f(array &$s): array { $b=(float)array_pop($s); $a=(float)array_pop($s); return [$a,$b]; }

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

    private static function u64ToGmp(int $a): \GMP
    {
        return $a >= 0 ? gmp_init($a) : gmp_add(gmp_init($a), gmp_pow(2, 64));
    }

    private static function gmpToI64(\GMP $v): int
    {
        if (gmp_cmp($v, gmp_pow(2, 63)) >= 0) {
            $v = gmp_sub($v, gmp_pow(2, 64));
        }
        return gmp_intval($v);
    }

    private static function int64Add(int $a, int $b): int
    {
        $r = gmp_add($a, $b);
        return self::gmpToI64(gmp_mod($r, gmp_pow(2, 64)));
    }

    private static function int64Sub(int $a, int $b): int
    {
        $r = gmp_sub($a, $b);
        return self::gmpToI64(gmp_mod($r, gmp_pow(2, 64)));
    }

    private static function int64Mul(int $a, int $b): int
    {
        $r = gmp_mul(self::u64ToGmp($a), self::u64ToGmp($b));
        return self::gmpToI64(gmp_mod($r, gmp_pow(2, 64)));
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

    private static function u64div(int $a, int $b): int
    {
        $ga = self::u64ToGmp($a);
        $gb = self::u64ToGmp($b);
        return self::gmpToI64(gmp_div_q($ga, $gb, GMP_ROUND_ZERO));
    }

    private static function u64rem(int $a, int $b): int
    {
        $ga = self::u64ToGmp($a);
        $gb = self::u64ToGmp($b);
        return self::gmpToI64(gmp_mod($ga, $gb));
    }

    private static function fmin(float $a, float $b): float
    {
        if (is_nan($a)||is_nan($b)) return NAN;
        if ($a===0.0&&$b===0.0) return (1/$a===-INF||1/$b===-INF)?-0.0:0.0;
        return min($a,$b);
    }

    private static function fmax(float $a, float $b): float
    {
        if (is_nan($a)||is_nan($b)) return NAN;
        if ($a===0.0&&$b===0.0) return (1/$a===INF||1/$b===INF)?0.0:-0.0;
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
        $neg=($b<0.0||(1/$b)===-INF);
        return $neg?-abs($a):abs($a);
    }

    private static function truncF2I32s(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a>=2147483648.0||$a<-2147483648.0) throw Trap::integerOverflow();
        return WasmValue::mask32((int)$a);
    }

    private static function truncF2I32u(float $a): int
    {
        if (is_nan($a)) throw Trap::invalidConversionToInteger();
        if (!is_finite($a)||$a>=4294967296.0||$a<0.0) throw Trap::integerOverflow();
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
        if (!is_finite($a)||$a<0.0||$a>=1.8446744073709552E+19) throw Trap::integerOverflow();
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

/** @internal Used to propagate early-return through doBranch */
final class EarlyReturn extends \Exception
{
    public function __construct(public readonly array $values) { parent::__construct(); }
}
