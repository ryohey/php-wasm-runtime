;; Control flow tests: block, loop, if, br, br_if, br_table, call

(module
  (func (export "block-empty")
    block end)

  (func (export "block-result-i32") (result i32)
    block (result i32)
      i32.const 1
    end)

  (func (export "block-br") (result i32)
    block (result i32)
      i32.const 2
      br 0
      i32.const 3
    end)

  (func (export "loop-sum") (param i32) (result i32)
    (local i32)
    block $exit
      loop $l
        local.get 0
        i32.eqz
        br_if $exit
        local.get 1
        local.get 0
        i32.add
        local.set 1
        local.get 0
        i32.const 1
        i32.sub
        local.set 0
        br $l
      end
    end
    local.get 1)

  (func (export "if-true") (param i32) (result i32)
    local.get 0
    if (result i32)
      i32.const 1
    else
      i32.const 0
    end)

  (func (export "if-false") (param i32) (result i32)
    local.get 0
    if (result i32)
      i32.const 0
    else
      i32.const 1
    end
    i32.eqz
    if (result i32)
      i32.const 42
    else
      i32.const 0
    end)

  (func (export "nested-br") (result i32)
    block $outer (result i32)
      block $inner (result i32)
        i32.const 5
        br $outer
        i32.const 10
      end
    end)

  ;; br_table with labeled blocks (blocks have void result to avoid passing values)
  (func (export "br-table") (param i32) (result i32)
    block $b0
      block $b1
        block $b2
          local.get 0
          br_table $b0 $b1 $b2
        end
        i32.const 2
        return
      end
      i32.const 1
      return
    end
    i32.const 0)

  (func (export "select") (param i32 i32 i32) (result i32)
    local.get 0
    local.get 1
    local.get 2
    select)
)

(assert_return (invoke "block-empty"))
(assert_return (invoke "block-result-i32") (i32.const 1))
(assert_return (invoke "block-br") (i32.const 2))
(assert_return (invoke "loop-sum" (i32.const 0)) (i32.const 0))
(assert_return (invoke "loop-sum" (i32.const 1)) (i32.const 1))
(assert_return (invoke "loop-sum" (i32.const 5)) (i32.const 15))
(assert_return (invoke "loop-sum" (i32.const 10)) (i32.const 55))
(assert_return (invoke "if-true" (i32.const 1)) (i32.const 1))
(assert_return (invoke "if-true" (i32.const 0)) (i32.const 0))
(assert_return (invoke "if-false" (i32.const 1)) (i32.const 42))
(assert_return (invoke "nested-br") (i32.const 5))
;; br_table: targets=[b0,b1], default=b2
;; I=0 → br $b0 (depth 2) → exit all → i32.const 0
;; I=1 → br $b1 (depth 1) → exit b1,b2 → i32.const 1 return
;; I=2 → default=$b2 (depth 0) → exit b2 → i32.const 2 return
;; I=3 → default=$b2 (depth 0) → same as I=2
(assert_return (invoke "br-table" (i32.const 0)) (i32.const 0))
(assert_return (invoke "br-table" (i32.const 1)) (i32.const 1))
(assert_return (invoke "br-table" (i32.const 2)) (i32.const 2))
(assert_return (invoke "br-table" (i32.const 3)) (i32.const 2))
(assert_return (invoke "select" (i32.const 1) (i32.const 2) (i32.const 1)) (i32.const 1))
(assert_return (invoke "select" (i32.const 1) (i32.const 2) (i32.const 0)) (i32.const 2))
