<?php
declare(strict_types=1);
namespace WasmRuntime;

/** Runtime trap (unreachable, OOB memory, integer divide by zero, etc.) */
final class Trap extends \RuntimeException
{
    public static function unreachable(): self              { return new self('unreachable'); }
    public static function integerDivideByZero(): self      { return new self('integer divide by zero'); }
    public static function integerOverflow(): self          { return new self('integer overflow'); }
    public static function invalidConversionToInteger(): self { return new self('invalid conversion to integer'); }
    public static function outOfBoundsMemoryAccess(): self  { return new self('out of bounds memory access'); }
    public static function outOfBoundsTableAccess(): self   { return new self('out of bounds table access'); }
    public static function indirectCallTypeMismatch(): self { return new self('indirect call type mismatch'); }
    public static function callStackExhausted(): self       { return new self('call stack exhausted'); }
    public static function undefinedElement(): self         { return new self('undefined element'); }
    public static function uninitializedElement(): self     { return new self('uninitialized element'); }
}
