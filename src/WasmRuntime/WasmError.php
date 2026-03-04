<?php

declare(strict_types=1);

namespace WasmRuntime;

/** Validation / parse / link error (not a runtime trap) */
final class WasmError extends \RuntimeException {}
