# WASI Test Suite Coverage

This document tracks the status of [wasi-testsuite](https://github.com/WebAssembly/wasi-testsuite) (Rust wasm32-wasip1 tests) against this runtime's WASI implementation.

- ✅ = Test passes (exit code 0)

> Tests run via `scripts/wasi-tests/run.php` against the PHP Wasm runtime CLI with `--wasi`.

## Results (2026-03-27)

**46 tests | 46 passing | 0 failing** ✅

### All Tests Passing

| Test | WASI syscalls exercised |
|---|---|
| `big_random_buf` | `random_get` |
| `clock_time_get` | `clock_time_get` |
| `close_preopen` | `fd_close` (preopened dirs) |
| `dangling_fd` | `path_open`, `fd_close` |
| `dangling_symlink` | `path_symlink`, `path_open` |
| `dir_fd_op_failures` | `fd_filestat_get`, filetype checks |
| `directory_seek` | `fd_seek`, directory rights |
| `fd_advise` | `fd_advise`, `fd_filestat_get` |
| `fd_fdstat_set_rights` | `fd_fdstat_set_rights` |
| `fd_filestat_set` | `fd_filestat_set_times`, `fd_filestat_set_size` |
| `fd_flags_set` | `fd_fdstat_set_flags` (append mode) |
| `fd_readdir` | `fd_readdir` |
| `file_allocate` | `fd_allocate`, `fd_filestat_get` |
| `file_pread_pwrite` | `fd_pread`, `fd_pwrite` |
| `file_seek_tell` | `fd_seek`, `fd_tell` |
| `file_truncation` | `path_open`, `fd_write`, `fd_read`, `fd_seek`, `fd_close` |
| `file_unbuffered_write` | `fd_write`, error handling |
| `fstflags_validate` | `path_filestat_set_times` validation |
| `interesting_paths` | `path_create_directory`, sandbox enforcement |
| `isatty` | `fd_fdstat_get` |
| `nofollow_errors` | `path_symlink`, symlink follow flags |
| `overwrite_preopen` | `fd_fdstat_get`, `fd_renumber` |
| `path_exists` | `path_filestat_get` |
| `path_filestat` | `path_filestat_get`, `path_filestat_set_times` |
| `path_link` | `path_link`, `path_symlink` |
| `path_open_create_existing` | `path_open` (OFLAGS_EXCL) |
| `path_open_dirfd_not_dir` | `path_open` (NOTDIR errno) |
| `path_open_missing` | `path_open` (NOENT error) |
| `path_open_nonblock` | `path_open` (O_NONBLOCK flag) |
| `path_open_preopen` | `path_open`, rights inheritance |
| `path_open_read_write` | `path_open`, read/write rights |
| `path_rename` | `path_rename`, `path_create_directory` |
| `path_rename_dir_trailing_slashes` | `path_rename` |
| `path_symlink_trailing_slashes` | `path_symlink` |
| `poll_oneoff_stdio` | `poll_oneoff` |
| `readlink` | `path_readlink` |
| `remove_directory_trailing_slashes` | `path_remove_directory`, `path_unlink_file` |
| `remove_nonempty_directory` | `path_remove_directory` |
| `renumber` | `fd_renumber` |
| `sched_yield` | `sched_yield` |
| `stdio` | stdio fd management, `fd_renumber` |
| `symlink_create` | `path_symlink` |
| `symlink_filestat` | `path_filestat_get`, `path_filestat_set_times` (symlinks) |
| `symlink_loop` | `path_symlink`, ELOOP detection |
| `truncation_rights` | `path_open` with `PATH_FILESTAT_SET_SIZE` right |
| `unlink_file_trailing_slashes` | `path_unlink_file` |

## Implemented WASI Syscalls

All `wasi_snapshot_preview1` syscalls used by the test suite are fully implemented:

| Syscall | Status | Notes |
|---|---|---|
| `args_get` | ✅ | |
| `args_sizes_get` | ✅ | |
| `clock_time_get` | ✅ | Monotonic and realtime |
| `clock_res_get` | ✅ | |
| `environ_get` | ✅ | |
| `environ_sizes_get` | ✅ | |
| `fd_advise` | ✅ | Advisory only (no-op) |
| `fd_allocate` | ✅ | |
| `fd_close` | ✅ | Including preopened dirs |
| `fd_datasync` | ✅ | |
| `fd_fdstat_get` | ✅ | Per-fd type, flags, and rights |
| `fd_fdstat_set_flags` | ✅ | Append mode toggle with file reopen |
| `fd_fdstat_set_rights` | ✅ | |
| `fd_filestat_get` | ✅ | Nanosecond-precision timestamps via Python |
| `fd_filestat_set_size` | ✅ | |
| `fd_filestat_set_times` | ✅ | Nanosecond-precision via Python `os.utime` |
| `fd_pread` | ✅ | |
| `fd_prestat_dir_name` | ✅ | |
| `fd_prestat_get` | ✅ | |
| `fd_pwrite` | ✅ | |
| `fd_read` | ✅ | |
| `fd_readdir` | ✅ | |
| `fd_renumber` | ✅ | |
| `fd_seek` | ✅ | With negative-position validation |
| `fd_sync` | ✅ | |
| `fd_tell` | ✅ | |
| `fd_write` | ✅ | |
| `path_create_directory` | ✅ | |
| `path_filestat_get` | ✅ | Nanosecond-precision timestamps via Python |
| `path_filestat_set_times` | ✅ | Nanosecond-precision via Python `os.utime` |
| `path_link` | ✅ | Symlink-aware via Python `os.link` |
| `path_open` | ✅ | Full rights enforcement, sandbox validation |
| `path_readlink` | ✅ | |
| `path_remove_directory` | ✅ | |
| `path_rename` | ✅ | |
| `path_symlink` | ✅ | |
| `path_unlink_file` | ✅ | |
| `poll_oneoff` | ✅ | Clock + FD read/write events |
| `proc_exit` | ✅ | |
| `random_get` | ✅ | |
| `sched_yield` | ✅ | No-op stub |

### Stub-only syscalls (not tested)

| Syscall | Notes |
|---|---|
| `sock_accept` | Returns NOSYS |
| `sock_recv` | Returns NOSYS |
| `sock_send` | Returns NOSYS |
| `sock_shutdown` | Returns NOSYS |

## Implementation Notes

### Nanosecond-precision timestamps

PHP's `stat()` and `touch()` only support second-precision timestamps. To pass the WASI timestamp tests which require nanosecond precision, the runtime shells out to Python 3:

- **Reading**: `os.lstat(path).st_mtime_ns` for nanosecond timestamps
- **Writing**: `os.utime(path, ns=(atime_ns, mtime_ns))` for setting timestamps
- **Fallback**: If Python 3 is unavailable, falls back to PHP's second-precision functions

### Hardlinks to symlinks (path_link)

PHP's `link()` always follows symlinks. For `path_link` with `LOOKUPFLAGS_SYMLINK_FOLLOW=0`, the runtime uses Python's `os.link(src, dst, follow_symlinks=False)` to create hardlinks to the symlink itself.

### Sandbox enforcement

All path operations validate that resolved paths stay within preopened directories. Path components are checked for `..` escape attempts, and absolute paths are rejected.

### Rights model

Each file descriptor tracks base rights and inheriting rights. Operations check the appropriate right before proceeding. New FDs opened via `path_open` inherit rights from the parent directory FD.

## Summary

| Category | Count |
|---|---|
| Passing | 46 |
| Failing | 0 |
| **Total** | **46** |

All 46 official wasi-testsuite (Rust wasm32-wasip1) tests pass against this PHP Wasm runtime's WASI implementation.
