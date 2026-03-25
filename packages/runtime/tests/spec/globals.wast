;; Global variable tests

(module
  (global $g (mut i32) (i32.const 0))
  (global $h i32 (i32.const 42))
  (global $fi (mut f64) (f64.const 0.0))

  (func (export "get_g") (result i32) global.get $g)
  (func (export "set_g") (param i32) global.get $g local.get 0 i32.add global.set $g)
  (func (export "get_h") (result i32) global.get $h)
  (func (export "get_fi") (result f64) global.get $fi)
  (func (export "set_fi") (param f64) local.get 0 global.set $fi)
)

(assert_return (invoke "get_g") (i32.const 0))
(invoke "set_g" (i32.const 5))
(assert_return (invoke "get_g") (i32.const 5))
(invoke "set_g" (i32.const 10))
(assert_return (invoke "get_g") (i32.const 15))

(assert_return (invoke "get_h") (i32.const 42))

(assert_return (invoke "get_fi") (f64.const 0.0))
(invoke "set_fi" (f64.const 3.14))
(assert_return (invoke "get_fi") (f64.const 3.14))
