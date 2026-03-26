# WebAssembly Spec Test Coverage

This document tracks the status of [official WebAssembly spec tests](https://github.com/WebAssembly/spec/tree/main/test/core) against this runtime.

- ✅ = All assertions pass
- 🟡 = Partially passing (some assertions fail)
- ⏭️ = Skipped (WABT incompatibility, GC proposal, binary format, etc.)

> Tests run via `WastTest.php` using WABT's `wast2json` to convert `.wast` → JSON + `.wasm` binaries.

## Results (2026-03-27)

**126 tests | 88 passing | 13 failing | 25 skipped**

### Control Flow
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `block.wast` | ✅ | all | |
| `loop.wast` | ✅ | all | |
| `if.wast` | ✅ | all | |
| `br.wast` | ✅ | all | |
| `br_if.wast` | ✅ | all | |
| `br_table.wast` | ✅ | all | |
| `return.wast` | ✅ | all | |
| `unreachable.wast` | ✅ | all | |
| `nop.wast` | ✅ | all | |
| `labels.wast` | ✅ | all | |
| `unwind.wast` | ✅ | all | |
| `stack.wast` | ✅ | all | |
| `break-drop.wast` | ✅ | all | |
| `switch.wast` | ✅ | all | |
| `unreached-invalid.wast` | ✅ | all | |

### Calls
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `call.wast` | ✅ | all | |
| `call_indirect.wast` | ✅ | all | |
| `return_call.wast` | ✅ | all | |
| `return_call_indirect.wast` | ✅ | all | |
| `fac.wast` | ✅ | all | |
| `forward.wast` | ✅ | all | |
| `func.wast` | ✅ | all | |
| `func_ptrs.wast` | ✅ | all | |
| `start.wast` | ✅ | all | |
| `left-to-right.wast` | ✅ | all | |

### Integers
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `i32.wast` | ✅ | all | |
| `i64.wast` | ✅ | all | |
| `int_exprs.wast` | ✅ | all | |
| `int_literals.wast` | ✅ | all | |

### Floats
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `f32.wast` | ✅ | all | |
| `f32_bitwise.wast` | ✅ | all | |
| `f32_cmp.wast` | ✅ | all | |
| `f64.wast` | 🟡 | 2511/2513 | 2 NaN arithmetic pattern failures |
| `f64_bitwise.wast` | 🟡 | 343/363 | 20 f64 NaN payload failures |
| `f64_cmp.wast` | ✅ | all | |
| `float_literals.wast` | ✅ | all | |
| `float_memory.wast` | 🟡 | 54/60 | 6 f32 NaN payload failures |
| `float_misc.wast` | 🟡 | 458/470 | 12 NaN payload failures |
| `conversions.wast` | 🟡 | 606/618 | 12 i64→f32 precision + NaN issues |
| `const.wast` | ✅ | all | |

### Memory
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `memory_grow.wast` | ✅ | all | |
| `memory_size.wast` | ✅ | all | |
| `memory_trap.wast` | ✅ | all | |
| `memory_redundancy.wast` | ✅ | all | |
| `address.wast` | ✅ | all | |
| `endianness.wast` | ✅ | all | |
| `load.wast` | ✅ | all | |
| `store.wast` | ✅ | all | |
| `data.wast` | ✅ | all | |

### Bulk Memory
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `memory_fill.wast` | ✅ | all | |
| `memory_copy.wast` | ✅ | all | |
| `memory_init.wast` | ✅ | all | |
| `bulk.wast` | 🟡 | 64/66 | 2 data segment init edge cases |

### Globals
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `global.wast` | 🟡 | 113/114 | 1 imported global edge case |

### Tables & Elements
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `table_get.wast` | ✅ | all | |
| `table_grow.wast` | ✅ | all | |
| `table_set.wast` | ✅ | all | |
| `table_size.wast` | ✅ | all | |
| `table_fill.wast` | ✅ | all | |
| `table_copy.wast` | ✅ | all | |
| `table_init.wast` | 🟡 | 728/729 | 1 edge case trap not triggered |
| `elem.wast` | 🟡 | 63/72 | 9 failures (drop/overlay, externref) |

### Locals
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `local_get.wast` | ✅ | all | |
| `local_set.wast` | ✅ | all | |
| `local_tee.wast` | 🟡 | 96/97 | 1 f32 NaN payload issue |

### Select / Type
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `select.wast` | 🟡 | 150/154 | 4 f32 NaN payload issues |
| `type.wast` | ✅ | all | |
| `traps.wast` | ✅ | all | |

### Imports / Exports / Linking
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `exports.wast` | ✅ | all | |
| `imports.wast` | 🟡 | 54/144 | 90 failures (multi-module import validation) |
| `linking.wast` | 🟡 | 77/133 | 56 failures (multi-module linking) |

### References
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `ref_func.wast` | ✅ | all | |
| `ref_is_null.wast` | ✅ | all | |

### Text Format / Validation
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `comments.wast` | ✅ | all | |
| `token.wast` | ✅ | all | |
| `names.wast` | ✅ | all | |
| `custom.wast` | ✅ | all | |
| `obsolete-keywords.wast` | ✅ | all | |
| `utf8-invalid-encoding.wast` | ✅ | all | |
| `unreached-valid.wast` | ✅ | all | |

### Skipped — WABT 1.0.39 Incompatibility
| Spec file | Notes |
|---|---|
| `align.wast` | WABT 1.0.39 doesn't support memory64 offsets > 0xFFFFFFFF |
| `id.wast` | WABT 1.0.39 doesn't support quoted identifiers ($"...") |
| `instance.wast` | WABT 1.0.39 doesn't support "module definition" syntax |
| `memory.wast` | WABT 1.0.39 doesn't support "module definition" syntax |
| `table.wast` | WABT 1.0.39 doesn't support "module definition" syntax |
| `table-sub.wast` | Uses GC proposal typed references |
| `inline-module.wast` | wast2json compatibility issue |

### Skipped — Binary Format
| Spec file | Notes |
|---|---|
| `binary.wast` | Tests binary encoding details |
| `binary-leb128.wast` | Tests binary encoding details |
| `utf8-custom-section-id.wast` | Binary format test |
| `utf8-import-field.wast` | Binary format test |
| `utf8-import-module.wast` | Binary format test |

### Skipped — GC Proposal / Typed Function References
| Spec file | Notes |
|---|---|
| `br_on_non_null.wast` | GC proposal |
| `br_on_null.wast` | GC proposal |
| `call_ref.wast` | GC proposal |
| `return_call_ref.wast` | GC proposal |
| `ref_as_non_null.wast` | GC proposal |
| `type-rec.wast` | GC proposal |
| `type-equivalence.wast` | GC proposal |
| `type-canon.wast` | GC proposal |
| `local_init.wast` | GC proposal |
| `ref_null.wast` | Uses anyref (WABT 1.0.39 unsupported) |

### Skipped — Other
| Spec file | Notes |
|---|---|
| `annotations.wast` | Annotations extension (non-standard syntax) |
| `skip-stack-guard-page.wast` | Deliberately exhausts native call stack |
| `float_exprs.wast` | Performance (>2000 lines, run separately) |

## Summary

| Category | Passing | Partial | Skipped |
|---|---|---|---|
| Control flow | 15 | 0 | 0 |
| Calls | 10 | 0 | 0 |
| Integers | 4 | 0 | 0 |
| Floats | 5 | 5 | 1 |
| Memory | 9 | 0 | 1 |
| Bulk memory | 3 | 1 | 0 |
| Globals | 0 | 1 | 0 |
| Tables/Elements | 5 | 2 | 2 |
| Locals | 2 | 1 | 1 |
| Select/Type | 2 | 1 | 0 |
| Imports/Exports/Linking | 1 | 2 | 1 |
| References | 2 | 0 | 1 |
| Text Format | 7 | 0 | 2 |
| Binary Format | 0 | 0 | 5 |
| GC Proposal | 0 | 0 | 10 |
| Other | 0 | 0 | 1 |
| **Total** | **65** | **13** | **25** |

Out of 101 non-skipped test files: **88 fully passing, 13 partially passing, 0 fully failing**.
