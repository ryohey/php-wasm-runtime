;; WASI args test: reads argc via args_sizes_get and prints it
(module
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "args_sizes_get"
    (func $args_sizes_get (param i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory 1)
  (export "memory" (memory 0))

  ;; Memory layout:
  ;;   0..3   argc
  ;;   4..7   argv_buf_size
  ;;   100    scratch for fd_write iovec and output
  ;;   200    nwritten

  (data (i32.const 300) "argc: ")   ;; prefix string at 300

  (func $write_str (param $ptr i32) (param $len i32)
    (i32.store (i32.const 100) (local.get $ptr))
    (i32.store (i32.const 104) (local.get $len))
    (drop (call $fd_write (i32.const 1) (i32.const 100) (i32.const 1) (i32.const 200)))
  )

  (func $write_i32 (param $n i32)
    ;; Write single digit 0-9 to address 400, then print it
    (i32.store8 (i32.const 400) (i32.add (local.get $n) (i32.const 48)))
    (call $write_str (i32.const 400) (i32.const 1))
  )

  (func $main
    ;; Get args sizes
    (drop (call $args_sizes_get (i32.const 0) (i32.const 4)))

    ;; Print "argc: "
    (call $write_str (i32.const 300) (i32.const 6))

    ;; Print argc value (single digit assumed for test)
    (call $write_i32 (i32.load (i32.const 0)))

    ;; Print newline
    (i32.store8 (i32.const 401) (i32.const 10))
    (call $write_str (i32.const 401) (i32.const 1))

    (call $proc_exit (i32.const 0))
  )

  (export "_start" (func $main))
)
