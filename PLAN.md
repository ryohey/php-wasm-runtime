# Implementation Plan & History

## Current Status (2026-03-26)

**Spec test results: 69/93 fully passing, 11 partially passing, 1 failing (21 skipped)**

### Completed Bug Fixes

#### Session: Spec Test Conformance (2026-03-26)

1. **f32.const infinity handling** — Parser unconditionally rejected `inf` values. Fixed by allowing literal `inf`/`-inf` in f32.const/f64.const while still rejecting overflow-to-infinity.

2. **f64.const overflow detection** — Added `rawString` to Token to distinguish literal `inf` from overflow. Lexer sets rawString when float parsing overflows.

3. **Duplicate export names** — Added `addExport()` with duplicate name detection. 19 tests fixed.

4. **UTF-8 validation for names** — Added `expectName()` with `mb_check_encoding()` at all import/export name sites. 176 tests fixed.

5. **Quoted identifiers ($"...")** — Extended Lexer's `readId()` for quoted form. Added `\u{NNNN}` Unicode escape in `readString()`. Extended `isIdChar`/`isSymChar`/`isSymStart` to accept bytes >= 0x80.

6. **Table constructor overflow** — Added 10M element guard to prevent `array_fill` memory exhaustion.

7. **f32 NaN payload preservation** — Implemented int-based NaN storage on the stack. f32 NaN values are stored as 32-bit integer bit patterns to avoid ARM64 sNaN→qNaN quieting during f32→f64 promotion. Key changes:
   - `WasmValue::f32Bits()` for NaN-safe bit extraction
   - `Memory::loadF32()` returns `int|float` (int for NaN)
   - `Memory::storeF32()` handles int NaN values
   - `Executor::asF32()` helper for int-NaN-to-float conversion
   - All f32 ops in Executor updated to use asF32()

8. **f32 NaN regression fix** — Fixed `Executor.php` line 108: changed `(float)$v` to `self::asF32($v)` for function return values.

9. **Float literal underscore validation** — Added context-aware underscore checks: rejects underscores adjacent to `.`, `e/E` (decimal), `p/P` (hex), and exponent signs. 28 tests fixed.

10. **Empty/malformed identifier detection** — Reject empty `$`, empty `$""`, control characters in strings, bad UTF-8 in quoted identifiers, missing separator after `$"..."`.

11. **Module quote handling** — WAST runner now properly concatenates quoted strings for `(module quote ...)` forms, enabling correct assert_malformed testing.

12. **WAT string escaping** — Added `escapeWatString()` to properly hex-escape control characters during token serialization.

13. **Obsolete operator rejection** — Reject opcodes containing `/` or `:` (e.g. `i32.wrap/i64`, `i32.trunc_s:sat/f32`).

14. **Param after result validation** — `parseFuncSig()` now rejects `(result ...)` before `(param ...)`.

15. **Duplicate global/table name detection** — Added duplicate checks for global and table names in both definitions and imports.

16. **Export index validation** — Validator now checks that exported func/table/memory/global indices exist.

17. **Forward reference pre-scanning** — Added `preScanOtherIds()` to register global/table/memory IDs before full parsing, enabling forward references from exports.

18. **Passive data segment handling** — Skip passive data segments during instantiation (they have no memory index).

---

## Remaining Failures

### High Priority (partially passing tests)

| Test file | Failures | Issue |
|---|---|---|
| `br_if.wast` | 1/118 | Validator: type mismatch not detected |
| `local_tee.wast` | 1/97 | Validator: type mismatch not detected |
| `exports.wast` | 1/41 | Tag export (exception handling proposal) |
| `float_literals.wast` | 1/177 | Binary module format not supported |
| `conversions.wast` | 12/618 | i64→f32/f64 conversion precision (needs software rounding) |

### Medium Priority

| Test file | Failures | Issue |
|---|---|---|
| `global.wast` | 14/114 | Imported global values not wired up, some validation |
| `table.wast` | 13/27 | Parsing issues (elem exprs), validation |
| `elem.wast` | 21/72 | Binary format (13), parsing (3), runtime (5) |
| `ref.wast` | 7/12 | Validation (typed ref checks) |

### Low Priority (multi-module linking)

| Test file | Failures | Issue |
|---|---|---|
| `imports.wast` | 122/144 | Multi-module import/linking infrastructure |
| `linking.wast` | 85/133 | Multi-module linking, re-exports |
| `instance.wast` | 12/12 | Definition syntax / multi-module |

### Not Implemented (Skipped)

- **Binary module format** (5 tests) — Only WAT text format is supported
- **GC Proposal** (10 tests) — Typed function references, rec types, etc.
- **Other** (6 tests) — Annotations, stack guard page, etc.

---

## Potential Next Steps

1. **i64→f32 conversion precision** — Implement software rounding for large i64 values. Would fix 8 conversions.wast failures.

2. **Imported global value wiring** — Wire up global values from registered modules. Would fix several global.wast failures.

3. **Element expression parsing** — Support `(elem ... (ref.func $f))` syntax. Would fix table.wast and elem.wast parsing failures.

4. **Multi-module linking infrastructure** — Major effort. Would fix imports.wast, linking.wast, instance.wast (219 total failures).

5. **Validator improvements** — Fix remaining br_if and local_tee validator edge cases.
