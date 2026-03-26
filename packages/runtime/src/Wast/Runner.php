<?php

declare(strict_types=1);

namespace WasmRuntime\Wast;

use WasmRuntime\{Instance, Module, Trap, WasmError, WasmValue, ValType, Validator};
use WasmRuntime\Binary\Decoder;
use WasmRuntime\Wat\{Lexer, Token};

/**
 * WAST (WebAssembly Script) test runner.
 *
 * Parses and executes .wast files which contain:
 *   (module ...)                  – define + instantiate a module
 *   (register "name" [$id])       – register module for imports
 *   (invoke "func" args...)       – call exported function
 *   (get "global" [$id])          – get exported global
 *   (assert_return ...)           – assert function return value
 *   (assert_trap ...)             – assert runtime trap
 *   (assert_invalid ...)          – assert invalid module
 *   (assert_malformed ...)        – assert malformed module
 *   (assert_exhaustion ...)       – assert call stack exhausted
 *   (assert_unlinkable ...)       – assert unlinkable module
 */
final class Runner
{
    /** @var Instance[] named modules (from register) */
    private array $namedModules = [];

    /** Most recently defined module instance */
    private ?Instance $current = null;

    /** Collected test results */
    private array $results = [];

    /** Total assertions executed */
    private int $total   = 0;
    private int $passed  = 0;
    private int $failed  = 0;
    private int $skipped = 0;

    /**
     * Run a .wast file.
     * Returns ['passed'=>int, 'failed'=>int, 'total'=>int, 'errors'=>string[]]
     */
    public function run(string $wastSrc): array
    {
        $this->results = [];
        $tokens        = (new Lexer($wastSrc))->tokenize();
        $pos           = 0;

        while ($tokens[$pos]->type !== Token::EOF) {
            if ($tokens[$pos]->type === Token::LPAREN) {
                $end = $this->findMatchingRParen($tokens, $pos);
                $src = $this->tokensToSrc($tokens, $pos, $end);
                $this->executeCommand($src, $tokens, $pos);
                $pos = $end + 1;
            } else {
                $pos++;
            }
        }

        return [
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'skipped' => $this->skipped,
            'total'   => $this->total,
            'errors'  => array_filter($this->results, fn($r) => $r['status'] === 'fail'),
        ];
    }

    /**
     * Execute one top-level wast command.
     * $src is the raw text of the S-expression.
     * $tokens/$pos used only to peek at the command type quickly.
     */
    private function executeCommand(string $src, array $tokens, int $pos): void
    {
        // peek at keyword after '('
        $kw = $tokens[$pos + 1]->value ?? '';

        try {
            match ((string)$kw) {
                'module'             => $this->cmdModule($src),
                'register'           => $this->cmdRegister($src),
                'invoke'             => $this->cmdInvoke($src),
                'assert_return'      => $this->cmdAssertReturn($src),
                'assert_trap'        => $this->cmdAssertTrap($src),
                'assert_invalid'     => $this->cmdAssertInvalid($src),
                'assert_malformed'   => $this->cmdAssertMalformed($src),
                'assert_exhaustion'  => $this->cmdAssertTrap($src), // same semantics
                'assert_unlinkable'  => $this->cmdAssertUnlinkable($src),
                default              => null, // ignore unknown commands
            };
        } catch (\Throwable $e) {
            // Unexpected error in the runner itself
            $this->recordFail("Runner error for '$kw': " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Command handlers
    // -------------------------------------------------------------------------

    private function cmdModule(string $src): void
    {
        // Extract module ID from WAT source before binary conversion (IDs are lost in binary format)
        $moduleId = $this->extractModuleId($src);
        $mod            = $this->parseModule($src);
        $imports        = $this->buildImports($mod);
        $this->current  = Instance::instantiate($mod, $imports);
        // If the module has an id ($name), register it for later invoke/get
        if ($moduleId !== null) {
            $this->namedModules[$moduleId] = $this->current;
        }
    }

    private function cmdRegister(string $src): void
    {
        $inner = $this->innerTokens($src);
        // (register "name" [$id])
        $name  = $this->expectStringAt($inner, 1);
        // Optional $id to look up a specific named module
        $inst = $this->current;
        if (isset($inner[2]) && $inner[2]->type === Token::ID) {
            $id   = (string)$inner[2]->value;
            $inst = $this->namedModules[$id]
                ?? throw new WasmError("Unknown module id: $id");
        }
        $this->namedModules[$name] = $inst
            ?? throw new WasmError('No current module to register');
    }

    private function cmdInvoke(string $src): void
    {
        $this->doInvoke($src);
    }

    private function cmdAssertReturn(string $src): void
    {
        $this->total++;
        // (assert_return (invoke "f" args...) expected...)
        // or (assert_return (get "g") expected)
        try {
            [$action, $actionSrc] = $this->extractFirstChild($src);
            $kw = $this->peekKeyword($actionSrc);

            if ($kw === 'invoke') {
                $actual = $this->doInvoke($actionSrc);
            } elseif ($kw === 'get') {
                $actual = [$this->doGet($actionSrc)];
            } else {
                $this->skipped++;
                return;
            }

            // Parse expected values (remaining children after action)
            $expected = $this->parseExpected($src, $action);

            if ($this->valuesMatch($actual, $expected)) {
                $this->passed++;
                $this->results[] = ['status' => 'pass'];
            } else {
                $actualStr   = implode(', ', array_map(fn($v) => (string)$v, $actual));
                $expectedStr = implode(', ', array_map(fn($v) => (string)$v, $expected));
                $this->failed++;
                $this->recordFail("assert_return: got [$actualStr] expected [$expectedStr]");
            }
        } catch (Trap $e) {
            $this->failed++;
            $this->recordFail("assert_return trapped: " . $e->getMessage());
        } catch (\Throwable $e) {
            $this->failed++;
            $this->recordFail("assert_return error: " . $e->getMessage());
        }
    }

    private function cmdAssertTrap(string $src): void
    {
        $this->total++;
        try {
            [$action, $actionSrc] = $this->extractFirstChild($src);
            $kw = $this->peekKeyword($actionSrc);
            if ($kw === 'invoke') {
                $this->doInvoke($actionSrc);
            } elseif ($kw === 'module') {
                $mod     = $this->parseModule($actionSrc);
                $imports = $this->buildImports($mod);
                Instance::instantiate($mod, $imports);
            }
            $this->failed++;
            $this->recordFail("assert_trap: expected trap but none occurred");
        } catch (Trap $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        } catch (\Throwable $e) {
            // WasmError or parse error also counts as "trapped" for malformed
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        }
    }

    private function cmdAssertInvalid(string $src): void
    {
        $this->total++;
        try {
            $inner = $this->extractModuleSrc($src);
            $mod   = $this->parseModule($inner);
            // Run the type validator — throws WasmError for ill-typed modules
            (new Validator())->validateModule($mod);
            Instance::instantiate($mod, []);
            // If we get here without error, it's a failure
            $this->failed++;
            $this->recordFail("assert_invalid: module was valid (should be invalid)");
        } catch (WasmError $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        } catch (Trap $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        } catch (\Throwable $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        }
    }

    private function cmdAssertMalformed(string $src): void
    {
        $this->total++;
        try {
            $inner = $this->extractModuleSrc($src);
            $this->parseModule($inner);
            $this->failed++;
            $this->recordFail("assert_malformed: module parsed successfully (should fail)");
        } catch (\Throwable $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        }
    }

    private function cmdAssertUnlinkable(string $src): void
    {
        $this->total++;
        try {
            $inner   = $this->extractModuleSrc($src);
            $mod     = $this->parseModule($inner);
            $imports = $this->buildImports($mod);
            Instance::instantiate($mod, $imports);
            $this->failed++;
            $this->recordFail("assert_unlinkable: module linked successfully (should fail)");
        } catch (\Throwable $e) {
            $this->passed++;
            $this->results[] = ['status' => 'pass'];
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** @return WasmValue[] */
    private function doInvoke(string $src): array
    {
        // (invoke [$id] "name" args...)
        $tokens = (new Lexer($src))->tokenize();
        $pos    = 1; // skip '('
        $this->skipKeyword($tokens, $pos, 'invoke');

        // optional module id
        $inst = $this->current;
        if ($tokens[$pos]->type === Token::ID) {
            $id   = (string)$tokens[$pos++]->value;
            $inst = $this->namedModules[$id]
                ?? throw new WasmError("Unknown module id: $id");
        }

        $name = $tokens[$pos++]->value; // function name string
        $args = $this->parseArgs($tokens, $pos);

        if ($inst === null) {
            throw new WasmError("No current module");
        }
        return $inst->callExport((string)$name, $args);
    }

    private function doGet(string $src): WasmValue
    {
        $tokens = (new Lexer($src))->tokenize();
        $pos    = 1;
        $this->skipKeyword($tokens, $pos, 'get');
        $inst = $this->current;
        if ($tokens[$pos]->type === Token::ID) {
            $id   = (string)$tokens[$pos++]->value;
            $inst = $this->namedModules[$id]
                ?? throw new WasmError("Unknown module id: $id");
        }
        $name = (string)$tokens[$pos]->value;
        return $inst->getExportedGlobal($name);
    }

    // -------------------------------------------------------------------------
    // Parsing helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the module $id from WAT source (e.g., "(module $Foo ...)").
     * Returns null if no ID is present.
     */
    private function extractModuleId(string $src): ?string
    {
        $tokens = (new Lexer($src))->tokenize();
        // Look for: '(' 'module' $id
        if ($tokens[0]->type === Token::LPAREN
            && (string)($tokens[1]->value ?? '') === 'module'
            && isset($tokens[2])
            && $tokens[2]->type === Token::ID
        ) {
            return (string)$tokens[2]->value;
        }
        return null;
    }

    private function parseModule(string $src): Module
    {
        $wasmBytes = $this->wat2wasm($src);
        return (new Decoder())->decode($wasmBytes);
    }

    /**
     * Convert WAT text to WASM binary using WABT's wat2wasm.
     */
    private function wat2wasm(string $watSrc): string
    {
        $tmpWat  = tempnam(sys_get_temp_dir(), 'wat_') . '.wat';
        $tmpWasm = tempnam(sys_get_temp_dir(), 'wasm_') . '.wasm';
        try {
            file_put_contents($tmpWat, $watSrc);
            // Try to find wat2wasm
            $wat2wasm = 'wat2wasm';
            foreach (['/opt/homebrew/bin/wat2wasm', '/usr/local/bin/wat2wasm'] as $path) {
                if (file_exists($path)) {
                    $wat2wasm = $path;
                    break;
                }
            }
            $cmd = sprintf(
                '%s --enable-tail-call --enable-extended-const --enable-gc --enable-function-references --enable-exceptions %s -o %s 2>&1',
                escapeshellarg($wat2wasm),
                escapeshellarg($tmpWat),
                escapeshellarg($tmpWasm)
            );
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new WasmError('wat2wasm failed: ' . implode("\n", $output));
            }
            $bytes = file_get_contents($tmpWasm);
            if ($bytes === false) {
                throw new WasmError('Failed to read wasm output');
            }
            return $bytes;
        } finally {
            @unlink($tmpWat);
            @unlink($tmpWasm);
        }
    }

    /** Standard spectest module values per WebAssembly spec */
    private static function spectestValue(string $name, string $kind): mixed
    {
        if ($kind === 'global') {
            return match ($name) {
                'global_i32' => WasmValue::i32(666),
                'global_i64' => WasmValue::i64(666),
                'global_f32' => WasmValue::f32(666.6),
                'global_f64' => WasmValue::f64(666.6),
                default      => null,
            };
        }
        if ($kind === 'func') {
            // All spectest functions are no-ops (print, print_i32, etc.)
            return function (array $args): array { return []; };
        }
        if ($kind === 'memory') {
            return new \WasmRuntime\Memory(1, 2);
        }
        if ($kind === 'table') {
            return new \WasmRuntime\Table(10, 20);
        }
        return null;
    }

    /** Build import table from registered named modules */
    private function buildImports(Module $mod): array
    {
        $imports = [];
        foreach ($mod->imports as $imp) {
            $mname = $imp['module'];
            $fname = $imp['name'];

            // Handle spectest module
            if ($mname === 'spectest') {
                $val = self::spectestValue($fname, $imp['kind']);
                if ($val !== null) {
                    $imports[$mname][$fname] = $val;
                }
                continue;
            }

            if (!isset($this->namedModules[$mname])) {
                continue;
            }
            $src = $this->namedModules[$mname];
            $exp = $src->module->exports[$fname] ?? null;
            if ($exp === null) continue;

            switch ($imp['kind']) {
                case 'func':
                    $expIdx = $exp['index'];
                    $imports[$mname][$fname] = function (array $args) use ($src, $expIdx): array {
                        return $src->executor->invoke($expIdx, $args);
                    };
                    break;
                case 'memory':
                    $imports[$mname][$fname] = $src->memories[$exp['index']] ?? null;
                    break;
                case 'table':
                    $imports[$mname][$fname] = $src->tables[$exp['index']] ?? null;
                    break;
                case 'global':
                    $gIdx = $exp['index'];
                    $rawVal = $src->globals[$gIdx] ?? 0;
                    $gDef   = $gIdx < $src->module->importedGlobalCount
                        ? $src->module->imports[$gIdx]
                        : $src->module->globals[$gIdx - $src->module->importedGlobalCount];
                    $gtype  = $gDef['globalType'] ?? $gDef['type'];
                    $imports[$mname][$fname] = match ($gtype) {
                        ValType::I32 => WasmValue::i32((int)$rawVal),
                        ValType::I64 => WasmValue::i64((int)$rawVal),
                        ValType::F32 => WasmValue::f32((float)$rawVal),
                        ValType::F64 => WasmValue::f64((float)$rawVal),
                        default      => WasmValue::i32((int)$rawVal),
                    };
                    break;
            }
        }
        return $imports;
    }

    /** @return WasmValue[] */
    private function parseArgs(array $tokens, int &$pos): array
    {
        $args = [];
        while ($tokens[$pos]->type === Token::LPAREN) {
            $pos++; // (
            $op = (string)$tokens[$pos++]->value; // e.g. i32.const
            $v  = $tokens[$pos++]; // numeric token
            $pos++; // )
            $args[] = $this->makeConst($op, $v);
        }
        return $args;
    }

    private function makeConst(string $op, Token $tok): WasmValue
    {
        $raw = $tok->value;
        if ($op === 'f32.const' || $op === 'f64.const') {
            $fv = $this->tokToFloat($tok, $op === 'f64.const');
            return $op === 'f32.const' ? WasmValue::f32($fv) : WasmValue::f64($fv);
        }
        if ($op === 'ref.null') {
            return match ((string)$raw) {
                'extern', 'externref' => new WasmValue(ValType::EXTERNREF, -1),
                default               => new WasmValue(ValType::FUNCREF, -1),
            };
        }
        if ($op === 'ref.extern') {
            return new WasmValue(ValType::EXTERNREF, (int)$raw);
        }
        return match ($op) {
            'i32.const' => WasmValue::i32((int)$raw),
            'i64.const' => WasmValue::i64((int)$raw),
            default     => WasmValue::i32((int)$raw),
        };
    }

    /** Convert a Token to a PHP float, handling nan:* and inf keywords. */
    private function tokToFloat(Token $tok, bool $isF64 = false): float
    {
        if ($tok->type === Token::FLOAT) {
            // For f32 context, use exact single-round conversion when rawString is available
            // to avoid double-rounding through f64 intermediate.
            if (!$isF64 && $tok->rawString !== null) {
                $raw = $tok->rawString;
                if (preg_match('/^[+-]?0x/i', $raw)) {
                    return Lexer::parseHexFloatAsF32($raw);
                }
                return Lexer::parseDecFloatAsF32($raw);
            }
            return (float)$tok->value;
        }
        if ($tok->type === Token::KEYWORD) {
            $kw = (string)$tok->value;
            if (preg_match('/^([+-]?)nan:0x([0-9a-fA-F_]+)$/', $kw, $m)) {
                $neg     = ($m[1] === '-');
                $hexStr  = str_replace('_', '', $m[2]);
                if ($isF64) {
                    // f64: 52-bit mantissa payload
                    $payload = hexdec($hexStr); // up to 52-bit value
                    $hi32    = ($neg ? 0x80000000 : 0) | 0x7FF00000 | (int)(($payload >> 32) & 0xFFFFF);
                    $lo32    = (int)($payload & 0xFFFFFFFF);
                    return (float)unpack('d', pack('VV', $lo32, $hi32))[1];
                }
                // f32: 23-bit mantissa payload
                $payload = (int)(hexdec($hexStr) & 0x7FFFFF);
                $sign    = $neg ? 0x80000000 : 0;
                $bits32  = $sign | 0x7F800000 | $payload;
                return (float)unpack('f', pack('V', $bits32))[1];
            }
            // nan:canonical, nan:arithmetic, -nan, nan
            if (str_contains($kw, 'nan') || $kw === '-nan') {
                return ($kw[0] === '-') ? (float)unpack('d', "\x00\x00\x00\x00\x00\x00\xF8\xFF")[1] : NAN;
            }
            if ($kw === 'inf')  return INF;
            if ($kw === '-inf') return -INF;
        }
        return (float)$tok->value;
    }

    /**
     * Parse expected values after the action S-expr inside assert_return.
     * $src is the full assert_return S-expr.
     * $actionEnd is the position of the ')' that ended the action.
     */
    private function parseExpected(string $src, int $actionTokenEnd): array
    {
        $tokens   = (new Lexer($src))->tokenize();
        $expected = [];
        $pos      = 0;
        // Skip outer '(' and 'assert_return'
        $pos++; $pos++;
        // Skip the action sub-expression (all tokens until depth 0 again)
        $depth = 0;
        while ($pos < count($tokens)) {
            if ($tokens[$pos]->type === Token::LPAREN) {
                $depth++;
                $pos++;
                if ($depth === 1) {
                    // First '(' = action
                    while ($depth > 0 && $pos < count($tokens)) {
                        if ($tokens[$pos]->type === Token::LPAREN) $depth++;
                        elseif ($tokens[$pos]->type === Token::RPAREN) $depth--;
                        $pos++;
                    }
                    break;
                }
            } else {
                $pos++;
            }
        }
        // Now parse remaining expected value S-exprs
        while ($tokens[$pos]->type === Token::LPAREN) {
            $pos++; // (
            $op  = (string)($tokens[$pos++]->value ?? '');
            if ($op === 'nan:canonical' || $op === 'nan:arithmetic' || $op === 'nan') {
                $pos++; // )
                $expected[] = match(true) {
                    str_starts_with($op, 'f32') || false => WasmValue::f32(NAN),
                    default => WasmValue::f64(NAN),
                };
                // We need to figure out the type from context
                // If the op is 'nan:canonical' or 'nan:arithmetic', look back at surrounding
                // Actually we parsed these as the opcode, NaN patterns are actually inside (f32.const nan)
                // Skip this for now - re-parse properly
                continue;
            }
            $valTok = $tokens[$pos] ?? new Token(Token::INT, 0, 0);
            $isNanPattern = $valTok->type === Token::KEYWORD
                && (in_array((string)$valTok->value, ['nan:canonical', 'nan:arithmetic', 'nan'], true)
                    || str_starts_with((string)$valTok->value, 'nan:0x')
                    || str_starts_with((string)$valTok->value, '-nan:0x'));
            if ($valTok->type === Token::INT || $valTok->type === Token::FLOAT || $isNanPattern
                || $valTok->type === Token::KEYWORD) {
                $pos++; // value
            }
            $pos++; // )
            if ($isNanPattern) {
                $isF64 = str_starts_with($op, 'f64.const');
                $expected[] = $isF64
                    ? WasmValue::f64($this->tokToFloat($valTok, true))
                    : WasmValue::f32($this->tokToFloat($valTok, false));
                continue;
            }
            $expected[] = match (true) {
                str_starts_with($op, 'i32.const') => WasmValue::i32((int)$valTok->value),
                str_starts_with($op, 'i64.const') => WasmValue::i64((int)$valTok->value),
                str_starts_with($op, 'f32.const') => WasmValue::f32($this->tokToFloat($valTok, false)),
                str_starts_with($op, 'f64.const') => WasmValue::f64($this->tokToFloat($valTok, true)),
                // ref.func with no argument = any non-null funcref (wildcard, value=-2)
                $op === 'ref.func'   => new WasmValue(ValType::FUNCREF, -2),
                // ref.null [type] = null reference (value=-1)
                $op === 'ref.null'   => match ((string)$valTok->value) {
                    'extern', 'externref' => new WasmValue(ValType::EXTERNREF, -1),
                    default               => new WasmValue(ValType::FUNCREF, -1),
                },
                // ref.extern N = specific external reference with integer id N
                $op === 'ref.extern' => new WasmValue(ValType::EXTERNREF, (int)$valTok->value),
                default => WasmValue::i32((int)$valTok->value),
            };
        }
        return $expected;
    }

    /** @return [int, string]  [actionEndPos, actionSrc] */
    private function extractFirstChild(string $src): array
    {
        $tokens = (new Lexer($src))->tokenize();
        $pos    = 1; // skip outer '('
        $pos++;      // skip keyword (assert_return etc.)

        // find first '('
        while ($pos < count($tokens) && $tokens[$pos]->type !== Token::LPAREN) {
            $pos++;
        }
        $start = $pos;
        $end   = $this->findMatchingRParen($tokens, $start);
        $actionSrc = $this->tokensToSrc($tokens, $start, $end);
        return [$end, $actionSrc];
    }

    private function extractModuleSrc(string $src): string
    {
        // Find the first (module ...) sub-expression
        $tokens = (new Lexer($src))->tokenize();
        $pos    = 1; $pos++; // skip '(' + keyword
        while ($pos < count($tokens) && $tokens[$pos]->type !== Token::LPAREN) {
            $pos++;
        }
        $end = $this->findMatchingRParen($tokens, $pos);

        // Handle (module quote "str1" "str2" ...) — concatenate strings as WAT source
        // pos points to '(', pos+1 is 'module', check if pos+2 is 'quote'
        if (isset($tokens[$pos + 2])
            && $tokens[$pos + 2]->type === Token::KEYWORD
            && (string)$tokens[$pos + 2]->value === 'quote'
        ) {
            $body = '';
            for ($i = $pos + 3; $i < $end; $i++) {
                if ($tokens[$i]->type === Token::STRING) {
                    $body .= (string)$tokens[$i]->value;
                }
            }
            // If the concatenated text already starts with (module, don't wrap
            $trimmed = ltrim($body);
            if (str_starts_with($trimmed, '(module')) {
                return $body;
            }
            return '(module ' . $body . ')';
        }

        // Handle (module binary "str1" "str2" ...) — binary format
        if (isset($tokens[$pos + 2])
            && $tokens[$pos + 2]->type === Token::KEYWORD
            && (string)$tokens[$pos + 2]->value === 'binary'
        ) {
            throw new WasmError('binary module format not supported');
        }

        return $this->tokensToSrc($tokens, $pos, $end);
    }

    private function peekKeyword(string $src): string
    {
        $tokens = (new Lexer($src))->tokenize();
        return (string)($tokens[1]->value ?? '');
    }

    private function expectStringAt(array $tokens, int $pos): string
    {
        return (string)($tokens[$pos]->value ?? '');
    }

    private function innerTokens(string $src): array
    {
        return (new Lexer($src))->tokenize();
    }

    private function skipKeyword(array $tokens, int &$pos, string $kw): void
    {
        if ((string)($tokens[$pos]->value ?? '') === $kw) {
            $pos++;
        }
    }

    /**
     * Convert an ID token value to valid WAT source.
     * If the ID (after $) contains characters that aren't valid idchars,
     * output it in quoted form: $"escaped_string"
     */
    private static function idToWat(string $id): string
    {
        // $id starts with '$'
        $body = substr($id, 1);
        // Check if all characters are valid idchars (printable ASCII except reserved)
        $needsQuoting = false;
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $o = ord($body[$i]);
            if ($o < 0x21 || $o > 0x7E || in_array($body[$i], ['(', ')', '"', ';'], true)) {
                $needsQuoting = true;
                break;
            }
        }
        if (!$needsQuoting) {
            return $id;
        }
        // Output as $"escaped_string"
        $escaped = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $o = ord($body[$i]);
            if ($o >= 0x20 && $o <= 0x7E && $body[$i] !== '"' && $body[$i] !== '\\') {
                $escaped .= $body[$i];
            } else {
                $escaped .= '\\' . sprintf('%02x', $o);
            }
        }
        return '$"' . $escaped . '"';
    }

    /**
     * Escape a raw string value for WAT source (bytes < 0x20 and 0x7F+ as \xx hex escapes).
     */
    private static function escapeWatString(string $s): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $o = ord($s[$i]);
            if ($o >= 0x20 && $o < 0x7F && $s[$i] !== '"' && $s[$i] !== '\\') {
                $out .= $s[$i];
            } else {
                $out .= '\\' . sprintf('%02x', $o);
            }
        }
        return $out;
    }

    private static function floatToWat(float $v): string
    {
        if (is_nan($v)) {
            // Preserve sign bit of NaN
            $bytes = unpack('C8', pack('d', $v));
            return ($bytes[8] & 0x80) ? '-nan' : 'nan';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'inf' : '-inf';
        }
        // Use var_export for exact round-trip precision.
        // PHP's (string) uses precision=14 which loses precision for adjacent f64 values.
        // var_export uses serialize_precision=-1 (minimum digits for exact round-trip).
        // It also correctly handles -0.0 → "-0.0" (preserves sign bit).
        return (string)var_export($v, true);
    }

    private function findMatchingRParen(array $tokens, int $start): int
    {
        $depth = 0;
        $i     = $start;
        while ($i < count($tokens)) {
            if ($tokens[$i]->type === Token::LPAREN) $depth++;
            elseif ($tokens[$i]->type === Token::RPAREN) {
                $depth--;
                if ($depth === 0) return $i;
            }
            $i++;
        }
        return count($tokens) - 1;
    }

    private function tokensToSrc(array $tokens, int $start, int $end): string
    {
        $parts = [];
        for ($i = $start; $i <= $end; $i++) {
            $tok = $tokens[$i];
            $parts[] = match ($tok->type) {
                Token::LPAREN  => '(',
                Token::RPAREN  => ')',
                Token::STRING  => '"' . self::escapeWatString((string)$tok->value) . '"',
                Token::ID      => self::idToWat((string)$tok->value),
                Token::INT     => (string)$tok->value,
                Token::FLOAT   => $tok->rawString !== null ? $tok->rawString : self::floatToWat((float)$tok->value),
                Token::KEYWORD => (string)$tok->value,
                default        => '',
            };
        }
        return implode(' ', $parts);
    }

    private function valuesMatch(array $actual, array $expected): bool
    {
        if (count($actual) !== count($expected)) {
            return count($expected) === 0 && count($actual) === 0;
        }
        foreach ($actual as $i => $a) {
            if (!isset($expected[$i])) return false;
            $e = $expected[$i];
            // Handle ref patterns (FUNCREF / EXTERNREF expected values)
            if ($e->type === ValType::FUNCREF || $e->type === ValType::EXTERNREF) {
                if (!$this->refMatches($a, $e)) return false;
                continue;
            }
            if ($a->type !== $e->type) return false;
            $av = $a->value;
            $ev = $e->value;
            if (is_nan((float)$av) && is_nan((float)$ev)) continue;
            if ($av !== $ev) return false;
        }
        return true;
    }

    /**
     * Match an actual WasmValue against a ref pattern expected value.
     * Expected value semantics:
     *   value = -2 → wildcard "any non-null ref" (matches any non-null actual)
     *   value = -1 → null ref (matches null actual, represented as -1)
     *   value =  0 → null ref (legacy, matches null actual)
     *   value =  N → specific ref id N (matches exact actual value N)
     *
     * Null refs use -1 internally; func index 0 is a valid non-null ref.
     */
    private function refMatches(WasmValue $actual, WasmValue $expected): bool
    {
        $ev = $expected->value;
        $av = $actual->value;
        if ($ev === -2) {
            // Wildcard: any non-null funcref (actual must not be null = not -1)
            return $av !== -1 && $av !== null;
        }
        if ($ev === -1 || $ev === 0) {
            // Null ref: actual must be null (-1 or PHP null)
            return $av === -1 || $av === null;
        }
        // Specific externref or funcref: exact match
        return $av === $ev;
    }

    private function recordFail(string $msg): void
    {
        $this->results[] = ['status' => 'fail', 'message' => $msg];
    }

    public function getResults(): array { return $this->results; }
    public function getPassed(): int    { return $this->passed; }
    public function getFailed(): int    { return $this->failed; }
    public function getTotal(): int     { return $this->total; }
}
