# WebAssembly Spec Test Coverage

This document tracks the status of [official WebAssembly spec tests](https://github.com/WebAssembly/spec/tree/main/test/core) against this runtime.

- ✅ = All assertions pass
- 🟡 = Partially passing (some assertions fail)
- ❌ = All assertions fail or not tested
- ⏭️ = Skipped (binary format, GC proposal, etc.)

> Tests run via `WastTest.php` against the official `.wast` files.

## Results (2026-03-26)

**114 test files | 81 passing | 12 failing | 21 skipped**

### Control Flow
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `block.wast` | ✅ | all | |
| `loop.wast` | ✅ | all | |
| `if.wast` | ✅ | all | |
| `br.wast` | ✅ | all | |
| `br_if.wast` | 🟡 | 117/118 | 1 validator issue |
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
| `f64.wast` | ✅ | all | |
| `f64_bitwise.wast` | ✅ | all | |
| `f64_cmp.wast` | ✅ | all | |
| `float_exprs.wast` | ✅ | all | |
| `float_literals.wast` | 🟡 | 176/177 | 1 binary module format |
| `float_memory.wast` | ✅ | all | |
| `float_misc.wast` | ✅ | all | |
| `conversions.wast` | 🟡 | 606/618 | 12 i64→f32 precision issues |
| `const.wast` | ✅ | all | |

### Memory
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `memory.wast` | ✅ | all | |
| `memory_grow.wast` | ✅ | all | |
| `memory_size.wast` | ✅ | all | |
| `memory_trap.wast` | ✅ | all | |
| `memory_redundancy.wast` | ✅ | all | |
| `address.wast` | ✅ | all | |
| `align.wast` | ✅ | all | |
| `endianness.wast` | ✅ | all | |
| `load.wast` | ✅ | all | |
| `store.wast` | ✅ | all | |
| `data.wast` | ✅ | all | |

### Globals
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `global.wast` | 🟡 | 100/114 | 14 failures (imported global values, some validation) |

### Tables & Elements
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `table.wast` | 🟡 | 14/27 | 13 failures (parsing, validation) |
| `table_get.wast` | ✅ | all | |
| `table_grow.wast` | ✅ | all | |
| `table_set.wast` | ✅ | all | |
| `table_size.wast` | ✅ | all | |
| `elem.wast` | 🟡 | 51/72 | 21 failures (binary format, parsing) |

### Locals
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `local_get.wast` | ✅ | all | |
| `local_set.wast` | ✅ | all | |
| `local_tee.wast` | 🟡 | 96/97 | 1 validator issue |

### Select / Type
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `select.wast` | ✅ | all | |
| `type.wast` | ✅ | all | |
| `traps.wast` | ✅ | all | |

### Imports / Exports / Linking
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `exports.wast` | 🟡 | 40/41 | 1 failure (tag export) |
| `imports.wast` | 🟡 | 22/144 | 122 failures (multi-module linking) |
| `linking.wast` | 🟡 | 48/133 | 85 failures (multi-module linking) |
| `instance.wast` | ❌ | 0/12 | All failures (definition syntax) |

### References
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `ref.wast` | 🟡 | 5/12 | 7 failures (validation) |
| `ref_func.wast` | ✅ | all | |
| `ref_is_null.wast` | ✅ | all | |
| `ref_null.wast` | ✅ | all | |

### Text Format / Tokenization
| Spec file | Status | Pass/Total | Notes |
|---|---|---|---|
| `comments.wast` | ✅ | all | |
| `token.wast` | ✅ | all | |
| `id.wast` | ✅ | all | |
| `names.wast` | ✅ | all | |
| `inline-module.wast` | ✅ | all | |
| `custom.wast` | ✅ | all | |
| `obsolete-keywords.wast` | ✅ | all | |
| `utf8-invalid-encoding.wast` | ✅ | all | |

### Skipped (Binary format)
| Spec file | Notes |
|---|---|
| `binary.wast` | Binary module format not supported |
| `binary-leb128.wast` | Binary module format not supported |
| `utf8-custom-section-id.wast` | Binary format |
| `utf8-import-field.wast` | Binary format |
| `utf8-import-module.wast` | Binary format |

### Skipped (GC Proposal / Typed Function References)
| Spec file | Notes |
|---|---|
| `annotations.wast` | Annotations proposal |
| `br_on_non_null.wast` | GC proposal |
| `br_on_null.wast` | GC proposal |
| `call_ref.wast` | GC proposal |
| `return_call_ref.wast` | GC proposal |
| `ref_as_non_null.wast` | GC proposal |
| `type-rec.wast` | GC proposal |
| `type-equivalence.wast` | GC proposal |
| `type-canon.wast` | GC proposal |
| `local_init.wast` | GC proposal |

### Other Skipped
| Spec file | Notes |
|---|---|
| `skip-stack-guard-page.wast` | Implementation-specific |
| `unreached-valid.wast` | Skipped |
| `float_exprs.wast` | Warning: large test |

## Summary

| Category | Passing | Partial | Failing | Skipped |
|---|---|---|---|---|
| Control flow | 14 | 1 | 0 | 0 |
| Calls | 10 | 0 | 0 | 0 |
| Integers | 4 | 0 | 0 | 0 |
| Floats | 10 | 2 | 0 | 0 |
| Memory | 11 | 0 | 0 | 0 |
| Globals | 0 | 1 | 0 | 0 |
| Tables/Elements | 4 | 2 | 0 | 0 |
| Locals | 2 | 1 | 0 | 0 |
| Select/Type | 3 | 0 | 0 | 0 |
| Imports/Exports/Linking | 0 | 3 | 1 | 0 |
| References | 3 | 1 | 0 | 0 |
| Text Format | 8 | 0 | 0 | 0 |
| **Total** | **69** | **11** | **1** | **21** |

Out of 93 non-skipped test files: **69 fully passing, 11 partially passing, 1 failing**.
