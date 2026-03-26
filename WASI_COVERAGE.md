# WASI Test Suite Coverage

This document tracks the status of [wasi-testsuite](https://github.com/WebAssembly/wasi-testsuite) (Rust wasm32-wasip1 tests) against this runtime's WASI implementation.

- ✅ = Test passes (exit code 0)
- ❌ = Test fails

> Tests run via `scripts/wasi-tests/run.php` against the PHP Wasm runtime CLI with `--wasi`.

## Results (2026-03-27)

**46 tests | 9 passing | 37 failing**

### Passing Tests

| Test | WASI syscalls exercised |
|---|---|
| `big_random_buf` | `random_get` |
| `clock_time_get` | `clock_time_get` |
| `dangling_fd` | `path_open`, `fd_close` |
| `file_truncation` | `path_open`, `fd_write`, `fd_read`, `fd_seek`, `fd_close` |
| `isatty` | `fd_fdstat_get` |
| `path_open_missing` | `path_open` (NOENT error) |
| `path_open_nonblock` | `path_open` (O_NONBLOCK flag) |
| `path_rename_dir_trailing_slashes` | `path_rename` (not implemented; test passes via error path) |
| `sched_yield` | `sched_yield` (stub) |

### Failing Tests — Missing WASI Syscalls

Many tests fail because they require WASI syscalls that are not yet implemented.

#### Not implemented: `fd_filestat_get` / `fd_filestat_set_times` / `fd_filestat_set_size`

| Test | Failure reason |
|---|---|
| `fd_advise` | Needs `fd_advise`, `fd_filestat_get` |
| `fd_filestat_set` | Needs `fd_filestat_set_times`, `fd_filestat_set_size` |
| `file_allocate` | Needs `fd_allocate`, `fd_filestat_get` |
| `path_exists` | Needs `path_filestat_get` for filetype detection |
| `path_filestat` | Needs `path_filestat_get`, `path_filestat_set_times` |
| `dir_fd_op_failures` | Needs `fd_filestat_get` for filetype checks |
| `symlink_filestat` | Needs `path_filestat_get` for symlink stats |

#### Not implemented: `fd_readdir`

| Test | Failure reason |
|---|---|
| `fd_readdir` | Needs `fd_readdir` |

#### Not implemented: `path_create_directory` / `path_remove_directory` / `path_unlink_file`

| Test | Failure reason |
|---|---|
| `interesting_paths` | Needs `path_create_directory` |
| `remove_directory_trailing_slashes` | Needs `path_remove_directory`, `path_unlink_file` |
| `remove_nonempty_directory` | Needs `path_remove_directory` |
| `unlink_file_trailing_slashes` | Needs `path_unlink_file` |

#### Not implemented: `path_symlink` / `path_readlink`

| Test | Failure reason |
|---|---|
| `dangling_symlink` | Needs `path_symlink`, `path_open` with symlink handling |
| `nofollow_errors` | Needs `path_symlink`, symlink follow flags |
| `symlink_create` | Needs `path_symlink` |
| `symlink_loop` | Needs `path_symlink`, ELOOP detection |
| `readlink` | Needs `path_readlink` |
| `path_symlink_trailing_slashes` | Needs `path_symlink` |

#### Not implemented: `path_link` / `path_rename`

| Test | Failure reason |
|---|---|
| `path_link` | Needs `path_link` |
| `path_rename` | Needs `path_rename`, `path_create_directory` |

#### Not implemented: `fd_renumber`

| Test | Failure reason |
|---|---|
| `renumber` | Needs `fd_renumber` |

#### Not implemented: `poll_oneoff`

| Test | Failure reason |
|---|---|
| `poll_oneoff_stdio` | Needs `poll_oneoff` |

#### Not implemented: `fd_fdstat_set_flags` / `fd_fdstat_set_rights`

| Test | Failure reason |
|---|---|
| `fd_fdstat_set_rights` | Needs `fd_fdstat_set_rights` |
| `fd_flags_set` | Needs `fd_fdstat_set_flags` |
| `truncation_rights` | Needs `fd_fdstat_get` with correct rights reporting |
| `directory_seek` | Needs proper rights reporting for directory FDs |
| `fstflags_validate` | Needs `path_filestat_set_times` with validation |

### Failing Tests — Existing Syscall Bugs

These tests fail due to bugs in already-implemented syscalls:

| Test | Issue |
|---|---|
| `close_preopen` | `fd_close` rejects pre-opened FDs (returns NOTSUP instead of allowing close) |
| `file_pread_pwrite` | `fd_pread`/`fd_pwrite` not implemented (only `fd_read`/`fd_write` exist) |
| `file_seek_tell` | `fd_seek`/`fd_tell` returns incorrect offset values |
| `file_unbuffered_write` | Writing to closed FD causes PHP notice instead of WASI error |
| `overwrite_preopen` | `fd_fdstat_get` returns wrong info after preopen FD is overwritten |
| `path_open_create_existing` | `path_open` returns ACCES instead of EXIST for exclusive create |
| `path_open_dirfd_not_dir` | `path_open` returns BADF instead of NOTDIR for non-directory dirfd |
| `path_open_preopen` | `path_open` doesn't properly handle rights inheritance from preopens |
| `path_open_read_write` | `path_open` doesn't enforce read/write rights based on oflags |
| `stdio` | FD duplication / preopen close handling incorrect |

## Implemented WASI Syscalls

| Syscall | Status | Notes |
|---|---|---|
| `args_get` | ✅ | |
| `args_sizes_get` | ✅ | |
| `environ_get` | ✅ | |
| `environ_sizes_get` | ✅ | |
| `clock_time_get` | ✅ | Monotonic and realtime |
| `fd_close` | 🟡 | Doesn't allow closing preopens |
| `fd_fdstat_get` | 🟡 | Basic filetype, no rights tracking |
| `fd_prestat_get` | ✅ | |
| `fd_prestat_dir_name` | ✅ | |
| `fd_read` | ✅ | |
| `fd_seek` | 🟡 | Offset issues |
| `fd_write` | ✅ | |
| `path_open` | 🟡 | Missing errno accuracy, rights enforcement |
| `proc_exit` | ✅ | |
| `random_get` | ✅ | |

## Not Implemented WASI Syscalls

| Syscall | Tests blocked |
|---|---|
| `fd_advise` | 1 |
| `fd_allocate` | 1 |
| `fd_datasync` | 0 |
| `fd_fdstat_set_flags` | 2 |
| `fd_fdstat_set_rights` | 1 |
| `fd_filestat_get` | 5+ |
| `fd_filestat_set_size` | 2 |
| `fd_filestat_set_times` | 2 |
| `fd_pread` | 1 |
| `fd_pwrite` | 1 |
| `fd_readdir` | 1 |
| `fd_renumber` | 1 |
| `fd_sync` | 0 |
| `fd_tell` | 1 |
| `path_create_directory` | 3 |
| `path_filestat_get` | 5+ |
| `path_filestat_set_times` | 2 |
| `path_link` | 1 |
| `path_readlink` | 1 |
| `path_remove_directory` | 2 |
| `path_rename` | 1 |
| `path_symlink` | 5 |
| `path_unlink_file` | 2 |
| `poll_oneoff` | 1 |
| `sock_accept` | 0 |
| `sock_recv` | 0 |
| `sock_send` | 0 |
| `sock_shutdown` | 0 |

## Summary

| Category | Count |
|---|---|
| Passing | 9 |
| Failing — missing syscalls | 27 |
| Failing — existing bugs | 10 |
| **Total** | **46** |

The 15 currently implemented syscalls cover the basic WASI functionality (stdio, args, env, clock, random, basic file I/O). The majority of failures (27/37) are due to unimplemented filesystem syscalls like `path_filestat_get`, `fd_readdir`, `path_symlink`, `path_create_directory`, etc.

## Potential Next Steps

1. **Fix existing syscall bugs** (10 tests) — Fix `fd_close` for preopens, `path_open` errno accuracy, `fd_seek` offset handling. Low effort, high impact.

2. **Implement `fd_filestat_get` / `path_filestat_get`** — Would unblock 5+ tests. Requires `fstat()`/`stat()` integration.

3. **Implement directory operations** — `path_create_directory`, `path_remove_directory`, `path_unlink_file`. Would unblock 7 tests.

4. **Implement symlink operations** — `path_symlink`, `path_readlink`. Would unblock 6 tests.

5. **Implement `fd_readdir`** — Would unblock 1 test. Requires directory entry enumeration.

6. **Implement `poll_oneoff`** — Would unblock 1 test. Complex to implement properly.
