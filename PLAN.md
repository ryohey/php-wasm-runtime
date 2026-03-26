# Implementation Plan & History

## Current Status (2026-03-27)

**Spec test results: 88/101 fully passing, 13 partially passing (25 skipped)**

Tests: 126, Assertions: 119, Failures: 13, Skipped: 25

### Architecture

- **Binary decoder** — reads `.wasm` files directly (`Binary\Decoder`, `Binary\BinaryReader`)
- **WAST runner** — uses WABT `wast2json` to convert `.wast` → JSON + `.wasm`, then executes test commands
- **No WAT parser** — the old `Wat\Lexer`, `Wat\Token`, `Wat\Parser` have been removed

### Completed Bug Fixes

#### Session: Architecture Migration (2026-03-27)

1. **Replaced WAST runner** — Rewrote `Wast\Runner` to use `wast2json` instead of the custom WAT lexer/token parser. This properly handles multi-module tests, binary format assertions, and all wast2json command types.

2. **Fixed Memory::fill() lazy allocation** — `substr_replace` at offsets beyond buffer length placed data at wrong position. Added buffer extension before `substr_replace`. Fixed memory_fill.wast (8→0) and memory_copy.wast (65→0).

3. **Fixed table.copy/table.init bounds checking** — Spec requires trap even with n=0 if indices are out of bounds. Added bounds check before early return.

4. **Implemented elem.drop/data.drop** — These instructions now actually clear the segment data (previously were no-ops), enabling subsequent table.init/memory.init to correctly fail.

5. **Fixed funcref matching in assert_return** — `(ref.func)` without args means "any non-null funcref" but wast2json encodes as `"value": "0"`. Fixed by treating all non-null funcref expected values as wildcards.

6. **Fixed f32 NaN arg parsing** — `bitsToF32()` returned int for NaN values, but `WasmValue::f32()` coerced int to float. Created `f32FromBits()` that creates WasmValue directly with int bit pattern for NaN.

7. **Added element segment bounds checking during instantiation** — Active element segments exceeding table size now properly trap.

8. **Removed unused WAT Lexer and Token classes** — Deleted `Wat\Lexer.php` (801 lines) and `Wat\Token.php` after migration to binary decoder + wast2json.

#### Session: Spec Test Conformance (2026-03-26)

1. **f32.const infinity handling** — Parser unconditionally rejected `inf` values. Fixed by allowing literal `inf`/`-inf`.

2. **f64.const overflow detection** — Added `rawString` to Token to distinguish literal `inf` from overflow.

3. **Duplicate export names** — Added `addExport()` with duplicate name detection. 19 tests fixed.

4. **UTF-8 validation for names** — Added `expectName()` with `mb_check_encoding()`. 176 tests fixed.

5. **Table constructor overflow** — Added 10M element guard to prevent `array_fill` memory exhaustion.

6. **f32 NaN payload preservation** — Implemented int-based NaN storage on the stack to avoid ARM64 sNaN→qNaN quieting.

7. **Float literal underscore validation** — Context-aware underscore checks. 28 tests fixed.

8. **Module quote handling** — WAST runner properly concatenates quoted strings for `(module quote ...)` forms.

9. **Passive data segment handling** — Skip passive data segments during instantiation.

---

## Remaining Failures (13 tests)

### Float NaN handling (PHP limitation)

| Test file | Failures | Issue |
|---|---|---|
| `f64.wast` | 2/2513 | NaN arithmetic pattern not preserved through PHP float ops |
| `f64_bitwise.wast` | 20/363 | f64 NaN payload lost (PHP floats don't preserve NaN payloads) |
| `float_memory.wast` | 6/60 | f32 NaN payload issues |
| `float_misc.wast` | 12/470 | f32/f64 NaN payload issues |
| `select.wast` | 4/154 | f32 NaN payload not preserved through select |
| `local_tee.wast` | 1/97 | f32 NaN payload not preserved through local.tee |

### Conversion precision

| Test file | Failures | Issue |
|---|---|---|
| `conversions.wast` | 12/618 | i64→f32 precision (needs software rounding), 2 NaN-related |

### Element/table operations

| Test file | Failures | Issue |
|---|---|---|
| `elem.wast` | 9/72 | Element segment drop/overlay behavior, externref handling |
| `table_init.wast` | 1/729 | Edge case: expected trap not triggered |
| `bulk.wast` | 2/66 | Data segment memory init edge case |

### Multi-module linking

| Test file | Failures | Issue |
|---|---|---|
| `imports.wast` | 90/144 | Multi-module import validation not fully implemented |
| `linking.wast` | 56/133 | Multi-module linking, re-exports |
| `global.wast` | 1/114 | Imported global value wiring edge case |

### Skipped (25 tests)

| Reason | Tests |
|---|---|
| WABT 1.0.39 incompatibility | `align.wast`, `id.wast`, `instance.wast`, `memory.wast`, `table.wast`, `table-sub.wast`, `inline-module.wast` |
| Binary format tests | `binary.wast`, `binary-leb128.wast`, `utf8-custom-section-id.wast`, `utf8-import-field.wast`, `utf8-import-module.wast` |
| GC proposal | `br_on_non_null.wast`, `br_on_null.wast`, `call_ref.wast`, `return_call_ref.wast`, `ref_as_non_null.wast`, `type-rec.wast`, `type-equivalence.wast`, `type-canon.wast`, `local_init.wast` |
| Reference types | `ref_null.wast` |
| Other | `annotations.wast`, `skip-stack-guard-page.wast`, `float_exprs.wast` (performance) |

---

## Potential Next Steps

1. **f32 NaN payload preservation** — Extend the int-based NaN storage approach (already used for f32) to cover all remaining NaN edge cases. Would fix select.wast, local_tee.wast, and reduce float_misc/float_memory failures.

2. **f64 NaN payload preservation** — Store f64 NaN values as int bit patterns (similar to f32 approach). Would fix f64.wast, f64_bitwise.wast failures.

3. **i64→f32 conversion precision** — Implement software rounding for large i64 values. Would fix 10 conversions.wast failures.

4. **Element segment improvements** — Fix elem.drop overlay behavior and externref handling. Would fix elem.wast failures.

5. **Multi-module linking** — Major effort. Would fix imports.wast (90 failures) and linking.wast (56 failures).

6. **Upgrade WABT** — Newer WABT versions support more syntax, reducing the 7 WABT-related skips.
