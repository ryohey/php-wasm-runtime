;; Function call tests

(module
  (func $fac (export "fac") (param i64) (result i64)
    local.get 0
    i64.const 0
    i64.le_s
    if (result i64)
      i64.const 1
    else
      local.get 0
      local.get 0
      i64.const 1
      i64.sub
      call $fac
      i64.mul
    end)

  (func $even (export "even") (param i32) (result i32)
    local.get 0
    i32.eqz
    if (result i32)
      i32.const 1
    else
      local.get 0
      i32.const 1
      i32.sub
      call $odd
    end)

  (func $odd (export "odd") (param i32) (result i32)
    local.get 0
    i32.eqz
    if (result i32)
      i32.const 0
    else
      local.get 0
      i32.const 1
      i32.sub
      call $even
    end)

  (func (export "add_one") (param i32) (result i32)
    local.get 0
    i32.const 1
    i32.add)

  (func (export "call_add_one") (param i32) (result i32)
    local.get 0
    call $add_one)

  (func $add_one (param i32) (result i32)
    local.get 0
    i32.const 1
    i32.add)
)

(assert_return (invoke "fac" (i64.const 0)) (i64.const 1))
(assert_return (invoke "fac" (i64.const 1)) (i64.const 1))
(assert_return (invoke "fac" (i64.const 5)) (i64.const 120))
(assert_return (invoke "fac" (i64.const 10)) (i64.const 3628800))

(assert_return (invoke "even" (i32.const 0)) (i32.const 1))
(assert_return (invoke "even" (i32.const 1)) (i32.const 0))
(assert_return (invoke "even" (i32.const 2)) (i32.const 1))
(assert_return (invoke "even" (i32.const 100)) (i32.const 1))

(assert_return (invoke "odd" (i32.const 0)) (i32.const 0))
(assert_return (invoke "odd" (i32.const 1)) (i32.const 1))
(assert_return (invoke "odd" (i32.const 200)) (i32.const 0))

(assert_return (invoke "call_add_one" (i32.const 0)) (i32.const 1))
(assert_return (invoke "call_add_one" (i32.const 99)) (i32.const 100))
