<?php
require 'vendor/autoload.php';

use WasmRuntime\Wat\Parser;
use WasmRuntime\Instance;
use WasmRuntime\WasmValue;

$module = (new Parser())->parseModule('
    (module
        (func (export "add") (param i32 i32) (result i32)
            local.get 0
            local.get 1
            i32.add)
    )
');

$instance = Instance::instantiate($module);

$results = $instance->callExport('add', [
    WasmValue::i32(10),
    WasmValue::i32(32),
]);

echo $results[0]->value; // 42