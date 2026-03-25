;; WASI hello world example
(module
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory 1)
  (export "memory" (memory 0))

  ;; Data: "Hello, WASI!\n" stored at offset 16
  (data (i32.const 16) "Hello, WASI!\n")

  (func $main
    ;; Set up iovec at address 0:
    ;;   buf     = 16  (pointer to string)
    ;;   buf_len = 13  (length of "Hello, WASI!\n")
    (i32.store (i32.const 0) (i32.const 16))   ;; iovec.buf = 16
    (i32.store (i32.const 4) (i32.const 13))   ;; iovec.buf_len = 13

    ;; fd_write(stdout=1, iovs=0, iovs_len=1, nwritten=8)
    (drop
      (call $fd_write
        (i32.const 1)   ;; fd = stdout
        (i32.const 0)   ;; iovs pointer
        (i32.const 1)   ;; iovs count
        (i32.const 8))) ;; nwritten pointer

    ;; proc_exit(0)
    (call $proc_exit (i32.const 0))
  )

  (export "_start" (func $main))
)
