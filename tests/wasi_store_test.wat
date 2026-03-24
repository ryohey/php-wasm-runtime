;; Test: store a specific value then read it back via fd_write
(module
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory 1)
  (export "memory" (memory 0))

  ;; "AB" at offset 200
  (data (i32.const 200) "AB")

  (func $main (local $len i32)
    ;; $len = 2
    (local.set $len (i32.const 2))

    ;; store iovec: buf=200, buf_len=$len
    (i32.store (i32.const 0) (i32.const 200))
    (i32.store (i32.const 4) (local.get $len))

    ;; fd_write(1, 0, 1, 8)  -- should write "AB"
    (drop (call $fd_write (i32.const 1) (i32.const 0) (i32.const 1) (i32.const 8)))

    (call $proc_exit (i32.const 0))
  )

  (export "_start" (func $main))
)
