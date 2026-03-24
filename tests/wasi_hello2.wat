;; Simpler WASI test: directly set up iovec without helper function
(module
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory 1)
  (export "memory" (memory 0))

  ;; String "Hello!\n" at offset 32
  (data (i32.const 32) "Hello!\n")

  (func $main
    ;; iovec at offset 0: {buf=32, buf_len=7}
    (i32.store (i32.const 0) (i32.const 32))
    (i32.store (i32.const 4) (i32.const 7))

    ;; fd_write(1, 0, 1, 16)
    (drop (call $fd_write
      (i32.const 1)
      (i32.const 0)
      (i32.const 1)
      (i32.const 16)))

    (call $proc_exit (i32.const 0))
  )

  (export "_start" (func $main))
)
