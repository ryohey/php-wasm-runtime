;; Test: pass length as function parameter
(module
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory 1)
  (export "memory" (memory 0))

  (data (i32.const 200) "HELLO")

  (func $write_str (param $ptr i32) (param $len i32)
    (i32.store (i32.const 0) (local.get $ptr))
    (i32.store (i32.const 4) (local.get $len))
    (drop (call $fd_write (i32.const 1) (i32.const 0) (i32.const 1) (i32.const 8)))
  )

  (func $main
    ;; Should print "HELLO" (5 bytes)
    (call $write_str (i32.const 200) (i32.const 5))
    (call $proc_exit (i32.const 0))
  )

  (export "_start" (func $main))
)
