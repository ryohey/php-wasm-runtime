;; Memory tests

(module
  (memory 1)
  (func (export "store_i32") (param i32 i32) i32.load (local.get 0) local.get 1 i32.store)
  (func (export "load_i32")  (param i32) (result i32) local.get 0 i32.load)
  (func (export "store8")    (param i32 i32) local.get 0 local.get 1 i32.store8)
  (func (export "load8_u")   (param i32) (result i32) local.get 0 i32.load8_u)
  (func (export "load8_s")   (param i32) (result i32) local.get 0 i32.load8_s)
  (func (export "store16")   (param i32 i32) local.get 0 local.get 1 i32.store16)
  (func (export "load16_u")  (param i32) (result i32) local.get 0 i32.load16_u)
  (func (export "load16_s")  (param i32) (result i32) local.get 0 i32.load16_s)
  (func (export "mem_size")  (result i32) memory.size)
  (func (export "mem_grow")  (param i32) (result i32) local.get 0 memory.grow)
  (func (export "store_f64") (param i32 f64) local.get 0 local.get 1 f64.store)
  (func (export "load_f64")  (param i32) (result f64) local.get 0 f64.load)
)

(assert_return (invoke "mem_size") (i32.const 1))

(invoke "store_i32" (i32.const 0) (i32.const 42))
(assert_return (invoke "load_i32" (i32.const 0)) (i32.const 42))

(invoke "store_i32" (i32.const 4) (i32.const 0x12345678))
(assert_return (invoke "load_i32" (i32.const 4)) (i32.const 0x12345678))

(invoke "store8" (i32.const 100) (i32.const 0xff))
(assert_return (invoke "load8_u" (i32.const 100)) (i32.const 255))
(assert_return (invoke "load8_s" (i32.const 100)) (i32.const -1))

(invoke "store8" (i32.const 200) (i32.const 0x7f))
(assert_return (invoke "load8_u" (i32.const 200)) (i32.const 127))
(assert_return (invoke "load8_s" (i32.const 200)) (i32.const 127))

(invoke "store16" (i32.const 300) (i32.const 0x8000))
(assert_return (invoke "load16_u" (i32.const 300)) (i32.const 0x8000))
(assert_return (invoke "load16_s" (i32.const 300)) (i32.const -32768))

(assert_return (invoke "mem_grow" (i32.const 1)) (i32.const 1))
(assert_return (invoke "mem_size") (i32.const 2))

(invoke "store_f64" (i32.const 0) (f64.const 1.5))
(assert_return (invoke "load_f64" (i32.const 0)) (f64.const 1.5))

;; Out of bounds (memory is 2 pages = 131072 bytes after grow)
(assert_trap (invoke "load_i32" (i32.const 0x20000)) "out of bounds memory access")
(assert_trap (invoke "store_i32" (i32.const 0x20000) (i32.const 0)) "out of bounds memory access")
