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
 * Label stack entry: [type, contIp, stackHeight, resultCount]
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
        Profiler::enter('executor.invoke');
        try {
            if (++$this->callDepth > self::MAX_CALL_DEPTH) {
                $this->callDepth--;
                throw Trap::callStackExhausted();
            }
            try {
                return $this->callFunction($funcIdx, $args);
            } finally {
                $this->callDepth--;
            }
        } finally {
            Profiler::leave('executor.invoke');
        }
    }

    /** @return WasmValue[] */
    private function callFunction(int $funcIdx, array $args): array
    {
        Profiler::enter('executor.callFunction');
        try {
            while (true) {
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
                    if (($a->type === ValType::FUNCREF || $a->type === ValType::EXTERNREF) && $a->value === -1) {
                        $locals[] = null;
                    } else {
                        $locals[] = $a->value;
                    }
                }
                foreach ($body['locals'] as $lt) {
                    $locals[] = match ($lt) {
                        ValType::I32, ValType::I64 => 0,
                        ValType::F32, ValType::F64 => 0.0,
                        ValType::FUNCREF, ValType::EXTERNREF => null,
                        default => 0,
                    };
                }

                try {
                    $rawResults = $this->run($body['code'], $locals, $ft);
                } catch (TailCallSignal $tcs) {
                    $funcIdx = $tcs->funcIdx;
                    $args    = $tcs->args;
                    continue;
                }

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
        } finally {
            Profiler::leave('executor.callFunction');
        }
    }

    /**
     * Main interpreter loop — flat bytecode dispatch.
     * @return (int|float)[]  raw result values
     */
    private function run(array $code, array $locals, FuncType $ft): array
    {
        Profiler::enter('executor.run');
        $stack      = [];
        $labelStack = [];   // [[type, contIp, stackHeight, resultCount]]
        $ip         = 0;
        $len        = count($code);
        $retCount   = count($ft->results);
        $mem0       = $this->instance->memories[0] ?? null;

        try {
        while ($ip < $len) {
            $op = $code[$ip++];

            switch ($op) {

                // ---- Control ----
                case Op::UNREACHABLE:
                    throw Trap::unreachable();

                case Op::NOP:
                    break;

                case Op::BLOCK: {
                    $blockType   = $code[$ip++]; // FuncType|null
                    $endIp       = $code[$ip++];
                    $paramCount  = $blockType ? count($blockType->params)  : 0;
                    $resultCount = $blockType ? count($blockType->results) : 0;
                    $labelStack[] = ['block', $endIp + 1, count($stack) - $paramCount, $resultCount];
                    break;
                }

                case Op::LOOP: {
                    $blockType   = $code[$ip++]; // FuncType|null
                    $contIp      = $code[$ip++];
                    $endIp       = $code[$ip++];
                    $paramCount  = $blockType ? count($blockType->params) : 0;
                    $labelStack[] = ['loop', $contIp, count($stack) - $paramCount, $paramCount];
                    break;
                }

                case Op::IF_: {
                    $blockType   = $code[$ip++]; // FuncType|null
                    $elseIp      = $code[$ip++];
                    $endIp       = $code[$ip++];
                    $hasElse     = ($elseIp !== $endIp);
                    $paramCount  = $blockType ? count($blockType->params)  : 0;
                    $resultCount = $blockType ? count($blockType->results) : 0;
                    $cond        = (int)array_pop($stack);

                    if ($cond !== 0) {
                        $labelStack[] = ['block', $endIp + 1, count($stack) - $paramCount, $resultCount];
                    } else {
                        if ($hasElse) {
                            $ip           = $elseIp + 2; // skip Op::ELSE_ + endIp
                            $labelStack[] = ['block', $endIp + 1, count($stack) - $paramCount, $resultCount];
                        } else {
                            $ip = $endIp + 1; // skip Op::END
                        }
                    }
                    break;
                }

                case Op::ELSE_: {
                    $endIp = $code[$ip++];
                    array_pop($labelStack);
                    $ip = $endIp + 1; // skip Op::END
                    break;
                }

                case Op::END: {
                    if (!empty($labelStack)) {
                        array_pop($labelStack);
                    }
                    break;
                }

                case Op::RETURN_: {
                    $n = count($stack);
                    return array_slice($stack, max(0, $n - $retCount));
                }

                case Op::BR: {
                    $depth      = $code[$ip++];
                    $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    break;
                }

                case Op::BR_IF: {
                    $depth = $code[$ip++];
                    $cond  = (int)array_pop($stack);
                    if ($cond !== 0) {
                        $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    }
                    break;
                }

                case Op::BR_TABLE: {
                    $cnt     = $code[$ip++]; // label count
                    $idx     = (int)array_pop($stack);
                    if ($idx >= 0 && $idx < $cnt) {
                        $depth = $code[$ip + $idx];
                    } else {
                        $depth = $code[$ip + $cnt]; // default
                    }
                    $ip += $cnt + 1; // skip all labels + default
                    $labelStack = $this->doBranch($depth, $stack, $labelStack, $retCount, $ip);
                    break;
                }

                case Op::CALL: {
                    $fIdx = $code[$ip++];
                    $cft  = $this->instance->module->funcType($fIdx);
                    $cargs = $this->popCallArgs($cft, $stack);
                    $this->pushCallResults($this->invoke($fIdx, $cargs), $stack);
                    break;
                }

                case Op::RETURN_CALL: {
                    $fIdx = $code[$ip++];
                    $cft  = $this->instance->module->funcType($fIdx);
                    $cargs = $this->popCallArgs($cft, $stack);
                    throw new TailCallSignal($fIdx, $cargs);
                }

                case Op::CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $elemIdx  = (int)array_pop($stack);
                    $cft      = $this->instance->module->types[$typeIdx];
                    $cargs    = $this->popCallArgs($cft, $stack);
                    $table = $this->instance->tables[$tableIdx]
                        ?? throw Trap::outOfBoundsTableAccess();
                    $fIdx  = $table->get($elemIdx);
                    if ($fIdx === null) throw Trap::uninitializedElement();
                    if (!$cft->equals($this->instance->module->funcType($fIdx)))
                        throw Trap::indirectCallTypeMismatch();
                    $this->pushCallResults($this->invoke($fIdx, $cargs), $stack);
                    break;
                }

                case Op::RETURN_CALL_INDIRECT: {
                    $typeIdx  = $code[$ip++];
                    $tableIdx = $code[$ip++];
                    $elemIdx  = (int)array_pop($stack);
                    $cft      = $this->instance->module->types[$typeIdx];
                    $cargs    = $this->popCallArgs($cft, $stack);
                    $table = $this->instance->tables[$tableIdx]
                        ?? throw Trap::outOfBoundsTableAccess();
                    $fIdx  = $table->get($elemIdx);
                    if ($fIdx === null) throw Trap::uninitializedElement();
                    if (!$cft->equals($this->instance->module->funcType($fIdx)))
                        throw Trap::indirectCallTypeMismatch();
                    throw new TailCallSignal($fIdx, $cargs);
                }

                // ---- Parametric ----
                case Op::DROP:   array_pop($stack); break;
                case Op::SELECT: {
                    $c = (int)array_pop($stack);
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $c !== 0 ? $a : $b;
                    break;
                }

                // ---- Variable ----
                case Op::LOCAL_GET:  $stack[] = $locals[$code[$ip++]]; break;
                case Op::LOCAL_SET:  $locals[$code[$ip++]] = array_pop($stack); break;
                case Op::LOCAL_TEE:  $locals[$code[$ip++]] = end($stack); break;
                case Op::GLOBAL_GET: $stack[] = $this->instance->globals[$code[$ip++]]; break;
                case Op::GLOBAL_SET: $this->instance->globals[$code[$ip++]] = array_pop($stack); break;

                // ---- Constants ----
                case Op::I32_CONST: $stack[] = $code[$ip++]; break;
                case Op::I64_CONST: $stack[] = $code[$ip++]; break;
                case Op::F32_CONST: $stack[] = $code[$ip++]; break;
                case Op::F64_CONST: $stack[] = $code[$ip++]; break;

                // ---- i32 arithmetic ----
                case Op::I32_ADD: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a+$b); break; }
                case Op::I32_SUB: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a-$b); break; }
                case Op::I32_MUL: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a*$b); break; }
                case Op::I32_DIV_S: {
                    $b=(int)array_pop($stack); $a=(int)array_pop($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===-2147483648 && $b===-1) throw Trap::integerOverflow();
                    $stack[]=WasmValue::mask32(intdiv($a,$b)); break;
                }
                case Op::I32_DIV_U: {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32((int)($a/$b)); break;
                }
                case Op::I32_REM_S: {
                    $b=(int)array_pop($stack); $a=(int)array_pop($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32($a%$b); break;
                }
                case Op::I32_REM_U: {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    if ($b===0) throw Trap::integerDivideByZero();
                    $stack[]=WasmValue::mask32($a%$b); break;
                }
                case Op::I32_AND:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a&$b); break; }
                case Op::I32_OR:    { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a|$b); break; }
                case Op::I32_XOR:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a^$b); break; }
                case Op::I32_SHL:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a<<($b&31)); break; }
                case Op::I32_SHR_S: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=WasmValue::mask32($a>>($b&31)); break; }
                case Op::I32_SHR_U: {
                    $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32($a>>($b&31)); break;
                }
                case Op::I32_ROTL: {
                    $b=WasmValue::u32((int)array_pop($stack))&31; $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32(($a<<$b)|($a>>(32-$b))); break;
                }
                case Op::I32_ROTR: {
                    $b=WasmValue::u32((int)array_pop($stack))&31; $a=WasmValue::u32((int)array_pop($stack));
                    $stack[]=WasmValue::mask32(($a>>$b)|($a<<(32-$b))); break;
                }
                case Op::I32_CLZ:    { $a=WasmValue::u32((int)array_pop($stack)); $stack[]=$a===0?32:self::clz32($a); break; }
                case Op::I32_CTZ:    { $a=WasmValue::u32((int)array_pop($stack)); $stack[]=$a===0?32:self::ctz($a); break; }
                case Op::I32_POPCNT: { $a=WasmValue::u32((int)array_pop($stack)); $n=0; while($a){$n+=$a&1;$a>>=1;} $stack[]=$n; break; }
                case Op::I32_EQZ:    $stack[]=((int)array_pop($stack)===0)?1:0; break;
                case Op::I32_EQ:     { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a===$b)?1:0; break; }
                case Op::I32_NE:     { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a!==$b)?1:0; break; }
                case Op::I32_LT_S:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a<$b)?1:0; break; }
                case Op::I32_LT_U:   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a<$b)?1:0; break; }
                case Op::I32_GT_S:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a>$b)?1:0; break; }
                case Op::I32_GT_U:   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a>$b)?1:0; break; }
                case Op::I32_LE_S:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a<=$b)?1:0; break; }
                case Op::I32_LE_U:   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a<=$b)?1:0; break; }
                case Op::I32_GE_S:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a>=$b)?1:0; break; }
                case Op::I32_GE_U:   { $b=WasmValue::u32((int)array_pop($stack)); $a=WasmValue::u32((int)array_pop($stack)); $stack[]=($a>=$b)?1:0; break; }

                // ---- i64 arithmetic ----
                case Op::I64_ADD: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::int64Add($a,$b); break; }
                case Op::I64_SUB: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::int64Sub($a,$b); break; }
                case Op::I64_MUL: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::int64Mul($a,$b); break; }
                case Op::I64_DIV_S: {
                    $b=(int)array_pop($stack); $a=(int)array_pop($stack);
                    if ($b===0) throw Trap::integerDivideByZero();
                    if ($a===PHP_INT_MIN && $b===-1) throw Trap::integerOverflow();
                    $stack[]=intdiv($a,$b); break;
                }
                case Op::I64_DIV_U: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=self::u64div($a,$b); break; }
                case Op::I64_REM_S: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=$a%$b; break; }
                case Op::I64_REM_U: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); if($b===0) throw Trap::integerDivideByZero(); $stack[]=self::u64rem($a,$b); break; }
                case Op::I64_AND:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=$a&$b; break; }
                case Op::I64_OR:    { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=$a|$b; break; }
                case Op::I64_XOR:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=$a^$b; break; }
                case Op::I64_SHL:   { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=$a<<($b&63); break; }
                case Op::I64_SHR_S: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=$a>>($b&63); break; }
                case Op::I64_SHR_U: { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::shr64u($a,$b&63); break; }
                case Op::I64_ROTL:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $b&=63; $stack[]=($a<<$b)|self::shr64u($a,64-$b); break; }
                case Op::I64_ROTR:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $b&=63; $stack[]=self::shr64u($a,$b)|($a<<(64-$b)); break; }
                case Op::I64_CLZ:   { $a=(int)array_pop($stack); $stack[]=$a===0?64:self::clz64($a); break; }
                case Op::I64_CTZ:   { $a=(int)array_pop($stack); $stack[]=$a===0?64:self::ctz($a); break; }
                case Op::I64_POPCNT:{ $a=(int)array_pop($stack); $n=0; for($b=0;$b<64;$b++){if(($a>>$b)&1)$n++;} $stack[]=$n; break; }
                case Op::I64_EQZ:   $stack[]=((int)array_pop($stack)===0)?1:0; break;
                case Op::I64_EQ:    { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a===$b)?1:0; break; }
                case Op::I64_NE:    { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a!==$b)?1:0; break; }
                case Op::I64_LT_S:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a<$b)?1:0; break; }
                case Op::I64_LT_U:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::u64cmp($a,$b)<0?1:0; break; }
                case Op::I64_GT_S:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a>$b)?1:0; break; }
                case Op::I64_GT_U:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::u64cmp($a,$b)>0?1:0; break; }
                case Op::I64_LE_S:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a<=$b)?1:0; break; }
                case Op::I64_LE_U:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::u64cmp($a,$b)<=0?1:0; break; }
                case Op::I64_GE_S:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=($a>=$b)?1:0; break; }
                case Op::I64_GE_U:  { $b=(int)array_pop($stack); $a=(int)array_pop($stack); $stack[]=self::u64cmp($a,$b)>=0?1:0; break; }

                // ---- f32 arithmetic ----
                case Op::F32_ADD:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32($a+$b); break; }
                case Op::F32_SUB:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32($a-$b); break; }
                case Op::F32_MUL:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32($a*$b); break; }
                case Op::F32_DIV:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32(self::fdiv($a,$b)); break; }
                case Op::F32_MIN:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32(self::fmin($a,$b)); break; }
                case Op::F32_MAX:     { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32(self::fmax($a,$b)); break; }
                case Op::F32_ABS: {
                    $v=array_pop($stack);
                    if (is_int($v)) { $stack[]=$v&0x7FFFFFFF; }
                    else { $stack[]=WasmValue::canonF32(abs((float)$v)); }
                    break;
                }
                case Op::F32_NEG: {
                    $v=array_pop($stack);
                    if (is_int($v)) { $stack[]=$v^(int)0x80000000; }
                    else { $stack[]=WasmValue::canonF32(-(float)$v); }
                    break;
                }
                case Op::F32_SQRT:    { $stack[]=WasmValue::canonF32(sqrt(self::asF32(array_pop($stack)))); break; }
                case Op::F32_CEIL:    { $stack[]=WasmValue::canonF32(ceil(self::asF32(array_pop($stack)))); break; }
                case Op::F32_FLOOR:   { $stack[]=WasmValue::canonF32(floor(self::asF32(array_pop($stack)))); break; }
                case Op::F32_TRUNC:   { $a=self::asF32(array_pop($stack)); $stack[]=WasmValue::canonF32($a>=0?floor($a):ceil($a)); break; }
                case Op::F32_NEAREST: { $stack[]=WasmValue::canonF32(self::nearest(self::asF32(array_pop($stack)))); break; }
                case Op::F32_COPYSIGN:{
                    $bv=array_pop($stack); $av=array_pop($stack);
                    $aBits=is_int($av)?$av:WasmValue::f32Bits((float)$av);
                    $bBits=is_int($bv)?$bv:WasmValue::f32Bits((float)$bv);
                    $result=($aBits&0x7FFFFFFF)|($bBits&(int)0x80000000);
                    if (($result&0x7FFFFFFF)>0x7F800000) { $stack[]=$result; }
                    else { $stack[]=(float)unpack('f',pack('V',$result))[1]; }
                    break;
                }
                case Op::F32_EQ:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a===$b)?1:0; break; }
                case Op::F32_NE:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a!==$b)?1:0; break; }
                case Op::F32_LT:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a<$b)?1:0; break; }
                case Op::F32_GT:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a>$b)?1:0; break; }
                case Op::F32_LE:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a<=$b)?1:0; break; }
                case Op::F32_GE:  { $b=self::asF32(array_pop($stack)); $a=self::asF32(array_pop($stack)); $stack[]=($a>=$b)?1:0; break; }

                // ---- f64 arithmetic ----
                case Op::F64_ADD:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=$a+$b; break; }
                case Op::F64_SUB:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=$a-$b; break; }
                case Op::F64_MUL:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=$a*$b; break; }
                case Op::F64_DIV:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=self::fdiv($a,$b); break; }
                case Op::F64_MIN:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=self::fmin($a,$b); break; }
                case Op::F64_MAX:     { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=self::fmax($a,$b); break; }
                case Op::F64_ABS:     { $stack[]=abs((float)array_pop($stack)); break; }
                case Op::F64_NEG:     { $stack[]=-(float)array_pop($stack); break; }
                case Op::F64_SQRT:    { $stack[]=sqrt((float)array_pop($stack)); break; }
                case Op::F64_CEIL:    { $stack[]=ceil((float)array_pop($stack)); break; }
                case Op::F64_FLOOR:   { $stack[]=floor((float)array_pop($stack)); break; }
                case Op::F64_TRUNC:   { $a=(float)array_pop($stack); $stack[]=$a>=0?floor($a):ceil($a); break; }
                case Op::F64_NEAREST: { $stack[]=self::nearest((float)array_pop($stack)); break; }
                case Op::F64_COPYSIGN:{ $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=self::copysign($a,$b); break; }
                case Op::F64_EQ:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a===$b)?1:0; break; }
                case Op::F64_NE:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a!==$b)?1:0; break; }
                case Op::F64_LT:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a<$b)?1:0; break; }
                case Op::F64_GT:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a>$b)?1:0; break; }
                case Op::F64_LE:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a<=$b)?1:0; break; }
                case Op::F64_GE:  { $b=(float)array_pop($stack); $a=(float)array_pop($stack); $stack[]=($a>=$b)?1:0; break; }

                // ---- Conversions ----
                case Op::I32_WRAP_I64:       { $stack[]=WasmValue::mask32((int)array_pop($stack)); break; }
                case Op::I32_TRUNC_F32_S:    { $stack[]=self::truncF2I32s(self::asF32(array_pop($stack))); break; }
                case Op::I32_TRUNC_F32_U:    { $stack[]=self::truncF2I32u(self::asF32(array_pop($stack))); break; }
                case Op::I32_TRUNC_F64_S:    { $stack[]=self::truncF2I32s((float)array_pop($stack)); break; }
                case Op::I32_TRUNC_F64_U:    { $stack[]=self::truncF2I32u((float)array_pop($stack)); break; }
                case Op::I32_TRUNC_SAT_F32_S:{ $stack[]=self::truncSatI32s(self::asF32(array_pop($stack))); break; }
                case Op::I32_TRUNC_SAT_F32_U:{ $stack[]=self::truncSatI32u(self::asF32(array_pop($stack))); break; }
                case Op::I32_TRUNC_SAT_F64_S:{ $stack[]=self::truncSatI32s((float)array_pop($stack)); break; }
                case Op::I32_TRUNC_SAT_F64_U:{ $stack[]=self::truncSatI32u((float)array_pop($stack)); break; }
                case Op::I64_EXTEND_I32_S:   { $stack[]=WasmValue::mask32((int)array_pop($stack)); break; }
                case Op::I64_EXTEND_I32_U:   { $stack[]=WasmValue::u32((int)array_pop($stack)); break; }
                case Op::I64_TRUNC_F32_S:    { $stack[]=self::truncF2I64s(self::asF32(array_pop($stack))); break; }
                case Op::I64_TRUNC_F32_U:    { $stack[]=self::truncF2I64u(self::asF32(array_pop($stack))); break; }
                case Op::I64_TRUNC_F64_S:    { $stack[]=self::truncF2I64s((float)array_pop($stack)); break; }
                case Op::I64_TRUNC_F64_U:    { $stack[]=self::truncF2I64u((float)array_pop($stack)); break; }
                case Op::I64_TRUNC_SAT_F64_S:{ $stack[]=self::truncSatI64s((float)array_pop($stack)); break; }
                case Op::I64_TRUNC_SAT_F64_U:{ $stack[]=self::truncSatI64u((float)array_pop($stack)); break; }
                case Op::I64_TRUNC_SAT_F32_S:{ $stack[]=self::truncSatI64s(self::asF32(array_pop($stack))); break; }
                case Op::I64_TRUNC_SAT_F32_U:{ $stack[]=self::truncSatI64u(self::asF32(array_pop($stack))); break; }
                case Op::F32_CONVERT_I32_S:  { $stack[]=WasmValue::canonF32((float)(int)array_pop($stack)); break; }
                case Op::F32_CONVERT_I32_U:  { $stack[]=WasmValue::canonF32((float)WasmValue::u32((int)array_pop($stack))); break; }
                case Op::F32_CONVERT_I64_S:  { $stack[]=WasmValue::canonF32((float)(int)array_pop($stack)); break; }
                case Op::F32_CONVERT_I64_U:  { $stack[]=WasmValue::canonF32(self::u64toFloat((int)array_pop($stack))); break; }
                case Op::F32_DEMOTE_F64:     { $stack[]=WasmValue::canonF32((float)array_pop($stack)); break; }
                case Op::F64_CONVERT_I32_S:  { $stack[]=(float)(int)array_pop($stack); break; }
                case Op::F64_CONVERT_I32_U:  { $stack[]=(float)WasmValue::u32((int)array_pop($stack)); break; }
                case Op::F64_CONVERT_I64_S:  { $stack[]=(float)(int)array_pop($stack); break; }
                case Op::F64_CONVERT_I64_U:  { $stack[]=self::u64toFloat((int)array_pop($stack)); break; }
                case Op::F64_PROMOTE_F32:    { $stack[]=self::asF32(array_pop($stack)); break; }
                case Op::I32_REINTERPRET_F32: {
                    $v=array_pop($stack);
                    $stack[]=is_int($v) ? WasmValue::mask32($v) : WasmValue::mask32(WasmValue::f32Bits((float)$v));
                    break;
                }
                case Op::I64_REINTERPRET_F64: {
                    $v=(float)array_pop($stack); $p=pack('d',$v);
                    $lo=unpack('V',$p)[1]; $hi=unpack('V',substr($p,4))[1];
                    $stack[]=($hi<<32)|$lo; break;
                }
                case Op::F32_REINTERPRET_I32: {
                    $v=(int)array_pop($stack);
                    $bits = $v & 0xFFFFFFFF;
                    if (($bits & 0x7FFFFFFF) > 0x7F800000) {
                        $stack[] = WasmValue::mask32($bits);
                    } else {
                        $stack[] = (float)unpack('f', pack('V', $bits))[1];
                    }
                    break;
                }
                case Op::F64_REINTERPRET_I64: {
                    $v=(int)array_pop($stack);
                    $lo=$v&0xFFFFFFFF; $hi=($v>>32)&0xFFFFFFFF;
                    $stack[]=unpack('d',pack('VV',$lo,$hi))[1]; break;
                }
                case Op::I32_EXTEND8_S:  { $v=(int)array_pop($stack)&0xFF;   $stack[]=WasmValue::mask32(($v&0x80)?$v|(-1<<8):$v); break; }
                case Op::I32_EXTEND16_S: { $v=(int)array_pop($stack)&0xFFFF; $stack[]=WasmValue::mask32(($v&0x8000)?$v|(-1<<16):$v); break; }
                case Op::I64_EXTEND8_S:  { $v=(int)array_pop($stack)&0xFF;   $stack[]=($v&0x80)?$v|(-1<<8):$v; break; }
                case Op::I64_EXTEND16_S: { $v=(int)array_pop($stack)&0xFFFF; $stack[]=($v&0x8000)?$v|(-1<<16):$v; break; }
                case Op::I64_EXTEND32_S: { $v=(int)array_pop($stack)&0xFFFFFFFF; $stack[]=($v&0x80000000)?$v|(-1<<32):$v; break; }

                // ---- Memory ----
                case Op::MEMORY_SIZE: $stack[]=$mem0->size(); break;
                case Op::MEMORY_GROW: { $stack[]=$mem0->grow((int)array_pop($stack)); break; }
                case Op::I32_LOAD:    { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI32(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD:    { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI64(($a&0xFFFFFFFF)+$off); break; }
                case Op::F32_LOAD:    { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadF32(($a&0xFFFFFFFF)+$off); break; }
                case Op::F64_LOAD:    { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadF64(($a&0xFFFFFFFF)+$off); break; }
                case Op::I32_LOAD8_S: { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI8s(($a&0xFFFFFFFF)+$off); break; }
                case Op::I32_LOAD8_U: { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI8u(($a&0xFFFFFFFF)+$off); break; }
                case Op::I32_LOAD16_S:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI16s(($a&0xFFFFFFFF)+$off); break; }
                case Op::I32_LOAD16_U:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI16u(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD8_S: { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI8s(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD8_U: { $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI8u(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD16_S:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI16s(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD16_U:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI16u(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD32_S:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadI32s(($a&0xFFFFFFFF)+$off); break; }
                case Op::I64_LOAD32_U:{ $off=$code[$ip++]; $a=(int)array_pop($stack); $stack[]=$mem0->loadU32(($a&0xFFFFFFFF)+$off); break; }
                case Op::I32_STORE:   { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI32(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I64_STORE:   { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI64(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::F32_STORE:   { $off=$code[$ip++]; $v=array_pop($stack); $a=(int)array_pop($stack); $mem0->storeF32(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::F64_STORE:   { $off=$code[$ip++]; $v=(float)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeF64(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I32_STORE8:  { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI8(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I32_STORE16: { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI16(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I64_STORE8:  { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI8(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I64_STORE16: { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI16(($a&0xFFFFFFFF)+$off,$v); break; }
                case Op::I64_STORE32: { $off=$code[$ip++]; $v=(int)array_pop($stack); $a=(int)array_pop($stack); $mem0->storeI32(($a&0xFFFFFFFF)+$off,$v); break; }

                // ---- Table ----
                case Op::TABLE_SIZE: {
                    $tIdx = $code[$ip++];
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[] = $table->size();
                    break;
                }
                case Op::TABLE_GROW: {
                    $tIdx = $code[$ip++];
                    $n    = (int)array_pop($stack);
                    $val  = array_pop($stack);
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[] = $table->grow($n, $val);
                    break;
                }
                case Op::TABLE_GET: {
                    $tIdx = $code[$ip++];
                    $idx  = (int)array_pop($stack);
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $stack[] = $table->get($idx);
                    break;
                }
                case Op::TABLE_SET: {
                    $tIdx = $code[$ip++];
                    $val  = array_pop($stack);
                    $idx  = (int)array_pop($stack);
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $table->set($idx, $val);
                    break;
                }
                case Op::TABLE_FILL: {
                    $tIdx = $code[$ip++];
                    $n    = (int)array_pop($stack);
                    $val  = array_pop($stack);
                    $i    = (int)array_pop($stack);
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    if ($i < 0 || $n < 0 || ($i & 0xFFFFFFFF) + ($n & 0xFFFFFFFF) > $table->size()) {
                        throw Trap::outOfBoundsTableAccess();
                    }
                    for ($k = 0; $k < $n; $k++) $table->set($i + $k, $val);
                    break;
                }
                case Op::TABLE_COPY: {
                    $dIdx = $code[$ip++];
                    $sIdx = $code[$ip++];
                    $n    = (int)array_pop($stack);
                    $s    = (int)array_pop($stack);
                    $d    = (int)array_pop($stack);
                    $su = $s & 0xFFFFFFFF; $du = $d & 0xFFFFFFFF; $nu = $n & 0xFFFFFFFF;
                    $dTable = $this->instance->tables[$dIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $sTable = $this->instance->tables[$sIdx] ?? throw Trap::outOfBoundsTableAccess();
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
                    $n    = (int)array_pop($stack);
                    $s    = (int)array_pop($stack);
                    $d    = (int)array_pop($stack);
                    $su = $s & 0xFFFFFFFF; $du = $d & 0xFFFFFFFF; $nu = $n & 0xFFFFFFFF;
                    $table = $this->instance->tables[$tIdx] ?? throw Trap::outOfBoundsTableAccess();
                    $elem  = $this->instance->module->elements[$eIdx] ?? null;
                    $funcIndices = ($elem && !empty($elem['funcIndices'])) ? $elem['funcIndices'] : [];
                    if ($su + $nu > count($funcIndices) || $du + $nu > $table->size()) {
                        throw Trap::outOfBoundsTableAccess();
                    }
                    for ($k = 0; $k < $nu; $k++) $table->set($du + $k, $funcIndices[$su + $k] ?? null);
                    break;
                }
                case Op::ELEM_DROP: {
                    $eIdx = $code[$ip++];
                    if (isset($this->instance->module->elements[$eIdx])) {
                        $this->instance->module->elements[$eIdx]['funcIndices'] = [];
                    }
                    break;
                }
                // ---- References ----
                case Op::REF_NULL:
                    $stack[] = null;
                    break;
                case Op::REF_FUNC: {
                    $fIdx = $code[$ip++];
                    $stack[] = $fIdx;
                    break;
                }
                case Op::REF_IS_NULL:
                    $stack[] = (array_pop($stack) === null) ? 1 : 0;
                    break;
                case Op::REF_AS_NON_NULL: {
                    $val = array_pop($stack);
                    if ($val === null) throw new Trap('null dereference');
                    $stack[] = $val;
                    break;
                }
                // ---- Memory bulk operations ----
                case Op::MEMORY_FILL: {
                    $n   = (int)array_pop($stack);
                    $val = (int)array_pop($stack);
                    $d   = (int)array_pop($stack);
                    $mem0->fill($d, $val & 0xFF, $n);
                    break;
                }
                case Op::MEMORY_COPY: {
                    $n = (int)array_pop($stack);
                    $s = (int)array_pop($stack);
                    $d = (int)array_pop($stack);
                    $mem0->copy($d, $s, $n);
                    break;
                }
                case Op::MEMORY_INIT: {
                    $segIdx = $code[$ip++];
                    $n = (int)array_pop($stack);
                    $s = (int)array_pop($stack);
                    $d = (int)array_pop($stack);
                    $data = $this->instance->module->dataSegments[$segIdx]['bytes'] ?? '';
                    $mem0->initFromData($d, $data, $s, $n);
                    break;
                }
                case Op::DATA_DROP: {
                    $dIdx = $code[$ip++];
                    if (isset($this->instance->module->dataSegments[$dIdx])) {
                        $this->instance->module->dataSegments[$dIdx]['bytes'] = '';
                    }
                    break;
                }

                default:
                    break; // unknown/future instructions silently skipped
            }
        }
        } catch (EarlyReturn $e) {
            return $e->values;
        } finally {
            Profiler::leave('executor.run');
        }

        $n = count($stack);
        return array_slice($stack, max(0, $n - $retCount));
    }

    // -------------------------------------------------------------------------
    // Branch logic
    // -------------------------------------------------------------------------

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
            $n    = count($stack);
            $vals = array_slice($stack, max(0, $n - $retCount));
            throw new EarlyReturn($vals);
        }

        [$type, $contIp, $stackHeight, $resultCount] = $labelStack[$targetIdx];

        $n       = count($stack);
        $topVals = ($resultCount > 0 && $n >= $resultCount) ? array_slice($stack, $n - $resultCount) : [];
        $stack   = array_slice($stack, 0, $stackHeight);
        foreach ($topVals as $v) {
            $stack[] = $v;
        }

        $ip = $contIp;

        if ($type === 'loop') {
            return array_slice($labelStack, 0, $targetIdx + 1);
        } else {
            return array_slice($labelStack, 0, $targetIdx);
        }
    }

    // -------------------------------------------------------------------------
    // Numeric helpers
    // -------------------------------------------------------------------------

    private function popCallArgs(FuncType $ft, array &$stack): array
    {
        $pc = count($ft->params);
        if ($pc === 0) {
            return [];
        }

        $args = array_fill(0, $pc, WasmValue::i32(0));
        for ($j = $pc - 1; $j >= 0; $j--) {
            $type = $ft->params[$j];
            $raw  = array_pop($stack);
            $args[$j] = match ($type) {
                ValType::I32 => WasmValue::i32((int)$raw),
                ValType::I64 => WasmValue::i64((int)$raw),
                ValType::F32 => WasmValue::f32((float)$raw),
                ValType::F64 => WasmValue::f64((float)$raw),
                ValType::FUNCREF   => new WasmValue(ValType::FUNCREF, $raw === null ? -1 : (int)$raw),
                ValType::EXTERNREF => new WasmValue(ValType::EXTERNREF, $raw === null ? -1 : (int)$raw),
                default      => WasmValue::i32((int)($raw ?? 0)),
            };
        }

        return $args;
    }

    private function pushCallResults(array $results, array &$stack): void
    {
        foreach ($results as $r) {
            $stack[] = ($r->type === ValType::FUNCREF || $r->type === ValType::EXTERNREF)
                ? ($r->value === -1 ? null : $r->value)
                : $r->value;
        }
    }

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
        $r = gmp_add($a, $b);
        return self::gmpToU64(gmp_mod($r, gmp_pow(2, 64)));
    }

    private static function int64Sub(int $a, int $b): int
    {
        $r = gmp_sub($a, $b);
        return self::gmpToU64(gmp_mod($r, gmp_pow(2, 64)));
    }

    private static function int64Mul(int $a, int $b): int
    {
        $r = gmp_mul($a, $b);
        return self::gmpToU64(gmp_mod($r, gmp_pow(2, 64)));
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

/** @internal Used to propagate early-return through doBranch */
final class EarlyReturn extends \Exception
{
    public function __construct(public readonly array $values) { parent::__construct(); }
}

/** @internal Signals a tail call (return_call / return_call_indirect) for TCO */
final class TailCallSignal extends \Exception
{
    /**
     * @param WasmValue[] $args
     */
    public function __construct(
        public readonly int   $funcIdx,
        public readonly array $args,
    ) {
        parent::__construct();
    }
}
