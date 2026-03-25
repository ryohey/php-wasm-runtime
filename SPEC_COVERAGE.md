# WebAssembly Spec Test Coverage

This document tracks which test files from the [official WebAssembly spec test suite](https://github.com/WebAssembly/spec/tree/main/test/core) are covered.

Files marked ✅ have corresponding `.wast` files in `tests/spec/` and all assertions pass.
Files marked 🚧 are partially implemented or in progress.
Files marked ❌ are not yet covered.

> Note: The `.wast` files in `tests/spec/` are hand-written test cases, not the full official spec files.
> To run against the official suite, download the `.wast` files from the link above and place them in `tests/spec/`.

## Instruction Categories

### Control Flow
| Spec file | Status | Notes |
|---|---|---|
| `block.wast` | ✅ | Covered via `control.wast` |
| `loop.wast` | ✅ | Covered via `control.wast` |
| `if.wast` | ✅ | Covered via `control.wast` |
| `br.wast` | ✅ | Covered via `control.wast` |
| `br_if.wast` | ✅ | Covered via `control.wast` |
| `br_table.wast` | ✅ | Covered via `control.wast` |
| `return.wast` | ✅ | Covered via `control.wast` |
| `unreachable.wast` | ✅ | Inline test passes |
| `nop.wast` | ✅ | `nop` opcode implemented |
| `labels.wast` | ❌ | Not yet |
| `unwind.wast` | ❌ | Not yet |
| `stack.wast` | ❌ | Not yet |

### Calls
| Spec file | Status | Notes |
|---|---|---|
| `call.wast` | ✅ | `tests/spec/call.wast` passes |
| `call_indirect.wast` | ✅ | Inline test passes |
| `fac.wast` | ❌ | Not yet |
| `forward.wast` | ❌ | Not yet |
| `func.wast` | ❌ | Not yet |
| `func_ptrs.wast` | ❌ | Not yet |
| `start.wast` | ❌ | Not yet |

### Integers
| Spec file | Status | Notes |
|---|---|---|
| `i32.wast` | ✅ | `tests/spec/i32.wast` passes |
| `i64.wast` | ❌ | Not yet (inline i64 tests pass) |
| `int_exprs.wast` | ❌ | Not yet |
| `int_literals.wast` | ❌ | Not yet |

### Floats
| Spec file | Status | Notes |
|---|---|---|
| `f32.wast` | ❌ | Not yet (f32 ops implemented) |
| `f32_bitwise.wast` | ❌ | Not yet |
| `f32_cmp.wast` | ❌ | Not yet |
| `f64.wast` | ✅ | `tests/spec/f64.wast` passes |
| `f64_bitwise.wast` | ❌ | Not yet |
| `f64_cmp.wast` | ❌ | Not yet |
| `float_exprs.wast` | ❌ | Not yet |
| `float_literals.wast` | ❌ | Not yet |
| `float_memory.wast` | ❌ | Not yet |
| `float_misc.wast` | ❌ | Not yet |

### Memory
| Spec file | Status | Notes |
|---|---|---|
| `memory.wast` | ✅ | `tests/spec/memory.wast` passes |
| `memory_grow.wast` | ❌ | Not yet |
| `memory_size.wast` | ❌ | Not yet |
| `memory_trap.wast` | ❌ | Not yet |
| `memory_redundancy.wast` | ❌ | Not yet |
| `address.wast` | ❌ | Not yet |
| `align.wast` | ❌ | Not yet |
| `endianness.wast` | ❌ | Not yet |
| `load.wast` | ❌ | Not yet |
| `store.wast` | ❌ | Not yet |
| `data.wast` | ❌ | Not yet |
| `memory_copy.wast` | ❌ | Not yet (bulk memory proposal) |
| `memory_fill.wast` | ❌ | Not yet (bulk memory proposal) |
| `memory_init.wast` | ❌ | Not yet (bulk memory proposal) |

### Globals
| Spec file | Status | Notes |
|---|---|---|
| `global.wast` | ✅ | Covered via `globals.wast` |

### Tables
| Spec file | Status | Notes |
|---|---|---|
| `table.wast` | ❌ | Not yet |
| `table_copy.wast` | ❌ | Not yet |
| `table_fill.wast` | ❌ | Not yet |
| `table_get.wast` | ❌ | Not yet |
| `table_grow.wast` | ❌ | Not yet |
| `table_init.wast` | ❌ | Not yet |
| `table_set.wast` | ❌ | Not yet |
| `table_size.wast` | ❌ | Not yet |
| `elem.wast` | ❌ | Not yet |
| `table-sub.wast` | ❌ | Not yet |

### Locals
| Spec file | Status | Notes |
|---|---|---|
| `local_get.wast` | ❌ | Not yet (implemented, no separate test) |
| `local_set.wast` | ❌ | Not yet (implemented, no separate test) |
| `local_tee.wast` | ❌ | Not yet (implemented, no separate test) |

### Select / Conversions
| Spec file | Status | Notes |
|---|---|---|
| `select.wast` | ✅ | Inline test passes |
| `conversions.wast` | ❌ | Not yet (many conversions implemented) |
| `const.wast` | ❌ | Not yet |

### Types / Validation
| Spec file | Status | Notes |
|---|---|---|
| `type.wast` | ❌ | Not yet |
| `typecheck.wast` | ❌ | Not yet |
| `traps.wast` | ❌ | Not yet |

### Imports / Exports / Linking
| Spec file | Status | Notes |
|---|---|---|
| `exports.wast` | ❌ | Not yet |
| `imports.wast` | ❌ | Not yet |
| `linking.wast` | ❌ | Not yet |

### References (post-MVP)
| Spec file | Status | Notes |
|---|---|---|
| `ref_func.wast` | ❌ | Not yet |
| `ref_is_null.wast` | ❌ | Not yet |
| `ref_null.wast` | ❌ | Not yet |

### Binary / Text Format
| Spec file | Status | Notes |
|---|---|---|
| `binary.wast` | ❌ | Binary format not supported |
| `binary-leb128.wast` | ❌ | Binary format not supported |
| `comments.wast` | ❌ | Not yet |
| `token.wast` | ❌ | Not yet |
| `inline-module.wast` | ❌ | Not yet |
| `names.wast` | ❌ | Not yet |
| `unicode.wast` | ❌ | Not yet |
| `custom.wast` | ❌ | Not yet |

### Misc
| Spec file | Status | Notes |
|---|---|---|
| `left-to-right.wast` | ❌ | Not yet |
| `switch.wast` | ❌ | Not yet |
| `skip-stack-guard-page.wast` | ❌ | Not yet |
| `obsolete-keywords.wast` | ❌ | Not yet |
| `unreached-invalid.wast` | ❌ | Not yet |
| `unreached-valid.wast` | ❌ | Not yet |
| `utf8-custom-section-id.wast` | ❌ | Not yet |
| `utf8-import-module.wast` | ❌ | Not yet |
| `utf8-import-name.wast` | ❌ | Not yet |
| `utf8-invalid-encoding.wast` | ❌ | Not yet |

## Summary

| Category | Covered | Total |
|---|---|---|
| Control flow | 9 | 12 |
| Calls | 3 | 7 |
| Integers | 1 | 4 |
| Floats | 1 | 10 |
| Memory | 1 | 14 |
| Globals | 1 | 1 |
| Tables | 0 | 10 |
| Locals | 0 | 3 |
| Select/Conversions | 1 | 3 |
| Types/Validation | 0 | 3 |
| Imports/Exports | 0 | 3 |
| References | 0 | 3 |
| Binary/Text | 0 | 8 |
| Misc | 0 | 10 |
| **Total** | **17** | **91** |

## Implemented Opcodes (not yet spec-tested)

The following instructions are implemented in `Executor.php` but don't yet have dedicated spec test files:

- **i64**: all arithmetic, bitwise, comparison ops
- **f32**: all arithmetic, comparison ops, `f32.const`, loads/stores
- **Conversions**: `i32.wrap_i64`, `i32.trunc_*`, `i64.extend_*`, `i64.trunc_*`, `f32.convert_*`, `f64.convert_*`, `*.reinterpret_*`, `i32.extend*_s`, `i64.extend*_s`
- **Memory**: all load/store variants (8/16/32/64-bit, signed/unsigned), `memory.size`, `memory.grow`
- **Table**: `call_indirect` (basic)
