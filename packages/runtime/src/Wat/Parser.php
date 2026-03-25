<?php

declare(strict_types=1);

namespace WasmRuntime\Wat;

use WasmRuntime\{FuncType, Memory, Module, ValType, WasmError, WasmValue};

/**
 * WAT (WebAssembly Text Format) parser.
 *
 * Parses a module S-expression and returns a Module ready for instantiation.
 * Supports:
 *   - func, memory, table, global, import, export, data, elem, start
 *   - Both folded (S-expr) and flat instruction syntax
 *   - Labels ($name) and numeric indices
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int    $pos = 0;

    // Accumulated during parsing
    private Module $mod;

    /** id -> index maps for the current module */
    private array $typeIds   = [];
    private array $funcIds   = [];
    private array $memIds    = [];
    private array $tableIds  = [];
    private array $globalIds = [];

    /** Label stack during bytecode compilation: innermost label first (null = anonymous) */
    private array $compileLabelStack = [];

    /** Local variable name -> index map for the function currently being parsed */
    private array $localNames = [];

    /** Set to true when any function body uses a memory-accessing instruction */
    private bool $memoryUsed = false;

    public function parseModule(string $src): Module
    {
        $this->tokens     = (new Lexer($src))->tokenize();
        $this->pos        = 0;
        $this->mod        = new Module();
        $this->memoryUsed = false;

        // Pre-scan to register all func IDs so forward references work.
        // Type IDs are registered in the first pass of module field parsing.
        $this->preScanFuncIds();

        $this->expect(Token::LPAREN);
        $this->expectKeyword('module');

        // optional module id ($name or bare keyword used as name)
        if ($this->peek()->type === Token::ID || $this->peek()->type === Token::KEYWORD && $this->peek()->value !== '(') {
            // only consume if it's not the start of a module field
            $next = $this->peek();
            if ($next->type === Token::ID) {
                $this->consume();
            } elseif ($next->type === Token::KEYWORD && !str_contains((string)$next->value, '.') &&
                      !in_array($next->value, ['type','import','func','table','memory','global','export','start','elem','data'], true)) {
                $this->consume();
            }
        }

        // Two-pass module field parsing:
        // Pass 1: parse only (type ...) fields so forward type references work
        // Pass 2: parse all other fields (they may reference types by id)
        $fieldPositions = [];
        while ($this->peek()->type !== Token::RPAREN && $this->peek()->type !== Token::EOF) {
            $fieldStart = $this->pos;
            if ($this->peekAhead(1)->value === 'type') {
                $this->parseModuleField();
            } else {
                // Skip to end of this S-expression
                $this->consume(); // (
                $depth = 1;
                while ($depth > 0 && $this->peek()->type !== Token::EOF) {
                    $t = $this->consume();
                    if ($t->type === Token::LPAREN)      $depth++;
                    elseif ($t->type === Token::RPAREN)  $depth--;
                }
            }
            $fieldPositions[] = $fieldStart;
        }
        $endPos = $this->pos; // position of closing ) of module

        // Pass 2: parse all non-type fields
        foreach ($fieldPositions as $pos) {
            if ($this->tokens[$pos + 1]->value !== 'type') {
                $this->pos = $pos;
                $this->parseModuleField();
            }
        }
        $this->pos = $endPos;
        $this->expect(Token::RPAREN);

        $this->resolveImportCounts();

        // Validate: memory instructions require a memory declaration or import
        if ($this->memoryUsed) {
            $totalMems = count($this->mod->memories) + $this->mod->importedMemoryCount;
            if ($totalMems === 0) {
                throw new WasmError("unknown memory 0");
            }
        }

        return $this->mod;
    }

    // -------------------------------------------------------------------------
    // Module fields
    // -------------------------------------------------------------------------

    private function parseModuleField(): void
    {
        $this->expect(Token::LPAREN);
        $kw = $this->expectKeyword(null);
        match ($kw) {
            'type'   => $this->parseType(),
            'import' => $this->parseImport(),
            'func'   => $this->parseFunc(),
            'table'  => $this->parseTable(),
            'memory' => $this->parseMemory(),
            'global' => $this->parseGlobal(),
            'export' => $this->parseExport(),
            'start'  => $this->parseStart(),
            'elem'   => $this->parseElem(),
            'data'   => $this->parseData(),
            default  => $this->skipSExpr(), // ignore unknown sections
        };
    }

    private function parseType(): void
    {
        $idx = count($this->mod->types);
        // optional id
        if ($this->peek()->type === Token::ID) {
            $this->typeIds[$this->consume()->value] = $idx;
        }
        $this->expect(Token::LPAREN);
        $this->expectKeyword('func');
        $ft = $this->parseFuncSig();
        $this->expect(Token::RPAREN); // close func
        $this->expect(Token::RPAREN); // close type
        $this->mod->types[] = $ft;
    }

    private function parseImport(): void
    {
        $modName  = $this->expect(Token::STRING)->value;
        $itemName = $this->expect(Token::STRING)->value;

        $this->expect(Token::LPAREN);
        $kw = $this->expectKeyword(null);
        switch ($kw) {
            case 'func':
                $funcIdx = $this->mod->importedFuncCount + count($this->mod->funcTypeIndices) + count(array_filter($this->mod->imports, fn($i) => $i['kind'] === 'func'));
                if ($this->peek()->type === Token::ID) {
                    $this->funcIds[$this->consume()->value] = count($this->mod->imports);
                }
                $typeIdx = $this->resolveTypeUse();
                $this->mod->imports[] = [
                    'kind'      => 'func',
                    'module'    => $modName,
                    'name'      => $itemName,
                    'typeIndex' => $typeIdx,
                ];
                break;
            case 'memory':
                if ($this->peek()->type === Token::ID) {
                    $this->consume();
                }
                [$min, $max] = $this->parseLimits();
                $this->mod->imports[] = [
                    'kind'   => 'memory',
                    'module' => $modName,
                    'name'   => $itemName,
                    'min'    => $min,
                    'max'    => $max,
                ];
                break;
            case 'table':
                if ($this->peek()->type === Token::ID) {
                    $this->consume();
                }
                [$min, $max] = $this->parseLimits();
                $refType = $this->expectKeyword(null); // funcref/externref
                $this->mod->imports[] = [
                    'kind'    => 'table',
                    'module'  => $modName,
                    'name'    => $itemName,
                    'min'     => $min,
                    'max'     => $max,
                    'refType' => $refType,
                ];
                break;
            case 'global':
                if ($this->peek()->type === Token::ID) {
                    $this->consume();
                }
                [$gtype, $mutable] = $this->parseGlobalType();
                $this->mod->imports[] = [
                    'kind'       => 'global',
                    'module'     => $modName,
                    'name'       => $itemName,
                    'globalType' => $gtype,
                    'mutable'    => $mutable,
                ];
                break;
        }
        $this->expect(Token::RPAREN); // close inner
        $this->expect(Token::RPAREN); // close import
    }

    private function parseFunc(): void
    {
        $localFuncIdx = count($this->mod->funcTypeIndices);
        $absFuncIdx   = count(array_filter($this->mod->imports, fn($i) => $i['kind'] === 'func')) + $localFuncIdx;

        // optional id
        if ($this->peek()->type === Token::ID) {
            $this->funcIds[$this->consume()->value] = $absFuncIdx;
        }

        // optional inline export/import
        while ($this->peek()->type === Token::LPAREN) {
            $saved = $this->pos;
            $this->consume(); // (
            $kw = $this->peek()->value ?? '';
            if ($kw === 'export') {
                $this->consume(); // export
                $exportName = $this->expect(Token::STRING)->value;
                $this->expect(Token::RPAREN);
                $this->mod->exports[$exportName] = ['kind' => 'func', 'index' => $absFuncIdx];
            } elseif ($kw === 'import') {
                // inline import
                $this->consume();
                $imod  = $this->expect(Token::STRING)->value;
                $iname = $this->expect(Token::STRING)->value;
                $this->expect(Token::RPAREN);
                $typeIdx = $this->resolveTypeUse();
                $this->mod->imports[] = [
                    'kind'      => 'func',
                    'module'    => $imod,
                    'name'      => $iname,
                    'typeIndex' => $typeIdx,
                ];
                $this->expect(Token::RPAREN);
                return;
            } else {
                $this->pos = $saved;
                break;
            }
        }

        // Reset local name map for this function; param names are captured inside resolveTypeUse()
        $this->localNames = [];
        $typeIdx = $this->resolveTypeUse(captureParamNames: true);
        $this->mod->funcTypeIndices[] = $typeIdx;

        // local declarations – capture names so $name-based local.get/set work
        $locals      = [];
        $localOffset = count($this->mod->types[$typeIdx]->params ?? []);
        while ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'local') {
            $this->consume(); // (
            $this->consume(); // local
            $localName = null;
            if ($this->peek()->type === Token::ID) {
                $localName = $this->consume()->value;
            }
            $prevCount = count($locals);
            while ($this->peek()->type === Token::KEYWORD && $this->isValType($this->peek()->value)) {
                $locals[] = ValType::fromString($this->consume()->value);
            }
            $added = count($locals) - $prevCount;
            if ($localName !== null) {
                $this->localNames[$localName] = $localOffset;
            }
            $localOffset += $added;
            $this->expect(Token::RPAREN);
        }

        $funcType  = $this->mod->types[$typeIdx] ?? new FuncType([], []);
        $params    = $funcType->params;
        $allLocals = array_merge(array_fill(0, count($params), 0), $locals);

        // Parse instructions (flat list, may be folded)
        $instrs = $this->parseInstrSeq($funcType);

        // Compile to flat instruction stream (reset label stack for each function)
        $this->compileLabelStack = [];
        $code = $this->compileInstr($instrs);

        $this->mod->funcBodies[] = ['locals' => $locals, 'code' => $code];
        $this->expect(Token::RPAREN);
    }

    private function parseTable(): void
    {
        $tableIdx = count($this->mod->tables);
        if ($this->peek()->type === Token::ID) {
            $this->tableIds[$this->consume()->value] = $tableIdx;
        }
        // optional inline export
        while ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'export') {
            $this->consume();
            $this->consume();
            $ename = $this->expect(Token::STRING)->value;
            $this->expect(Token::RPAREN);
            $this->mod->exports[$ename] = ['kind' => 'table', 'index' => $tableIdx];
        }

        // inline elem shorthand: (table funcref (elem funcidx...))
        if ($this->peek()->type === Token::LPAREN) {
            // skip reftype-less form: table $id (elem ...)
        }

        // Check for inline elem: limits reftype (elem ...)  OR  reftype (elem ...)
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            [$min, $max] = $this->parseLimits();
            // reftype may be a keyword (funcref/externref) or (ref ...)
            if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'ref') {
                $this->skipRefType();
                $refType = 'funcref';
            } else {
                $refType = $this->expectKeyword(null);
            }
            $this->mod->tables[] = ['type' => $refType, 'min' => $min, 'max' => $max];
        } elseif ($tok->type === Token::KEYWORD && ($tok->value === 'funcref' || $tok->value === 'externref')) {
            // inline form: funcref (elem ...)
            $refType = $this->consume()->value;
            $this->expect(Token::LPAREN);
            $this->expectKeyword('elem');
            $funcIndices = [];
            while ($this->peek()->type !== Token::RPAREN) {
                $funcIndices[] = $this->resolveFuncIdx();
            }
            $this->expect(Token::RPAREN);
            $min = count($funcIndices);
            $this->mod->tables[] = ['type' => $refType, 'min' => $min, 'max' => $min];
            $tableIdx2 = count($this->mod->tables) - 1;
            $this->mod->elements[] = [
                'tableIndex'  => $tableIdx2,
                'offset'      => 0,
                'funcIndices' => $funcIndices,
            ];
        } elseif ($tok->type === Token::LPAREN && $this->peekAhead(1)->value === 'ref') {
            // inline form: (ref null $t) (elem ...)
            $this->skipRefType();
            $refType = 'funcref';
            if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'elem') {
                $this->consume(); // (
                $this->consume(); // elem
                $funcIndices = [];
                while ($this->peek()->type !== Token::RPAREN) {
                    $funcIndices[] = $this->resolveFuncIdx();
                }
                $this->expect(Token::RPAREN);
                $min = count($funcIndices);
                $this->mod->tables[] = ['type' => $refType, 'min' => $min, 'max' => $min];
                $tableIdx2 = count($this->mod->tables) - 1;
                $this->mod->elements[] = [
                    'tableIndex'  => $tableIdx2,
                    'offset'      => 0,
                    'funcIndices' => $funcIndices,
                ];
            } else {
                $this->mod->tables[] = ['type' => $refType, 'min' => 0, 'max' => null];
            }
        }
        $this->expect(Token::RPAREN);
    }

    private function parseMemory(): void
    {
        $memIdx = count($this->mod->memories);
        if ($this->peek()->type === Token::ID) {
            $this->memIds[$this->consume()->value] = $memIdx;
        }
        // optional inline export
        while ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'export') {
            $this->consume();
            $this->consume();
            $ename = $this->expect(Token::STRING)->value;
            $this->expect(Token::RPAREN);
            $this->mod->exports[$ename] = ['kind' => 'memory', 'index' => $memIdx];
        }
        // inline data shorthand: (memory (data "..."))
        if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'data') {
            $this->consume();
            $this->consume();
            $data = '';
            while ($this->peek()->type === Token::STRING) {
                $data .= $this->consume()->value;
            }
            $this->expect(Token::RPAREN);
            $pages = (int)ceil(strlen($data) / Memory::PAGE_SIZE);
            $this->mod->memories[] = ['min' => $pages, 'max' => $pages];
            $this->mod->dataSegments[] = ['memIndex' => $memIdx, 'offset' => 0, 'bytes' => $data];
        } else {
            [$min, $max] = $this->parseLimits();
            $this->mod->memories[] = ['min' => $min, 'max' => $max];
        }
        $this->expect(Token::RPAREN);
    }

    private function parseGlobal(): void
    {
        $gIdx = count($this->mod->globals);
        if ($this->peek()->type === Token::ID) {
            $this->globalIds[$this->consume()->value] = $gIdx;
        }
        // optional inline export
        while ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'export') {
            $this->consume();
            $this->consume();
            $ename = $this->expect(Token::STRING)->value;
            $this->expect(Token::RPAREN);
            $this->mod->exports[$ename] = ['kind' => 'global', 'index' => $gIdx];
        }
        [$gtype, $mutable] = $this->parseGlobalType();
        $initExpr = $this->parseConstExpr();
        $this->mod->globals[] = ['type' => $gtype, 'mutable' => $mutable, 'init' => $initExpr];
        $this->expect(Token::RPAREN);
    }

    private function parseExport(): void
    {
        $name = $this->expect(Token::STRING)->value;
        $this->expect(Token::LPAREN);
        $kw  = $this->expectKeyword(null);
        $idx = match ($kw) {
            'func'   => $this->resolveFuncIdx(),
            'table'  => $this->resolveTableIdx(),
            'memory' => $this->resolveMemIdx(),
            'global' => $this->resolveGlobalIdx(),
            default  => throw new WasmError("Unknown export kind: $kw"),
        };
        $this->expect(Token::RPAREN);
        $this->expect(Token::RPAREN);
        $this->mod->exports[$name] = ['kind' => $kw, 'index' => $idx];
    }

    private function parseStart(): void
    {
        $this->mod->startFunc = $this->resolveFuncIdx();
        $this->expect(Token::RPAREN);
    }

    private function parseElem(): void
    {
        // Simple form: (elem (table N) (offset expr) funcref (elem funcidx...))
        // OR: (elem (offset expr) funcidx...)
        $tableIdx = 0;
        $offset   = 0;

        if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'table') {
            $this->consume();
            $this->consume();
            $tableIdx = $this->resolveTableIdx();
            $this->expect(Token::RPAREN);
        }

        if ($this->peek()->type === Token::LPAREN) {
            $kw = $this->peekAhead(1)->value;
            if ($kw === 'offset') {
                $this->consume();
                $this->consume();
                $offset = $this->parseConstExprVal();
                $this->expect(Token::RPAREN);
            } elseif ($this->isInstrKeyword($kw)) {
                $offset = $this->parseConstExprVal();
            }
        }

        // optional reftype keyword
        if ($this->peek()->type === Token::KEYWORD && in_array($this->peek()->value, ['funcref', 'func', 'externref'], true)) {
            $this->consume();
        }

        // optional inner (item ...) or just func indices
        $funcIndices = [];
        while ($this->peek()->type !== Token::RPAREN) {
            if ($this->peek()->type === Token::LPAREN) {
                $this->consume();
                $inner = $this->expectKeyword(null);
                if ($inner === 'item') {
                    // skip
                    $this->skipUntilRParen();
                } elseif ($inner === 'func') {
                    while ($this->peek()->type !== Token::RPAREN) {
                        $funcIndices[] = $this->resolveFuncIdx();
                    }
                    $this->expect(Token::RPAREN);
                } else {
                    $this->skipUntilRParen();
                }
            } else {
                $funcIndices[] = $this->resolveFuncIdx();
            }
        }
        $this->expect(Token::RPAREN);

        if ($funcIndices) {
            $this->mod->elements[] = [
                'tableIndex'  => $tableIdx,
                'offset'      => $offset,
                'funcIndices' => $funcIndices,
            ];
        }
    }

    private function parseData(): void
    {
        $memIdx = 0;
        $offset = 0;
        if ($this->peek()->type === Token::INT || $this->peek()->type === Token::ID) {
            $memIdx = $this->resolveMemIdx();
        }
        if ($this->peek()->type === Token::LPAREN) {
            $kw = $this->peekAhead(1)->value;
            if ($kw === 'offset') {
                $this->consume();
                $this->consume();
                $offset = $this->parseConstExprVal();
                $this->expect(Token::RPAREN);
            } elseif ($this->isInstrKeyword($kw)) {
                $offset = $this->parseConstExprVal();
            }
        }
        $data = '';
        while ($this->peek()->type === Token::STRING) {
            $data .= $this->consume()->value;
        }
        $this->expect(Token::RPAREN);
        $this->mod->dataSegments[] = ['memIndex' => $memIdx, 'offset' => $offset, 'bytes' => $data];
    }

    // -------------------------------------------------------------------------
    // Type parsing helpers
    // -------------------------------------------------------------------------

    private function parseFuncSig(): FuncType
    {
        $params  = [];
        $results = [];
        while ($this->peek()->type === Token::LPAREN) {
            $kw = $this->peekAhead(1)->value;
            if ($kw === 'param') {
                $this->consume(); // (
                $this->consume(); // param
                if ($this->peek()->type === Token::ID) {
                    $this->consume(); // param id
                }
                $this->consumeValTypesInto($params);
                $this->expect(Token::RPAREN);
            } elseif ($kw === 'result') {
                $this->consume();
                $this->consume();
                $this->consumeValTypesInto($results);
                $this->expect(Token::RPAREN);
            } else {
                break;
            }
        }
        return new FuncType($params, $results);
    }

    /**
     * Parse typeuse: optional (type idx) then optional (param)* (result)*
     *
     * @param bool $captureParamNames When true, named params populate $this->localNames.
     */
    private function resolveTypeUse(bool $captureParamNames = false): int
    {
        $typeIdx = -1;
        if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'type') {
            $this->consume();
            $this->consume();
            $typeIdx = $this->resolveTypeIdx();
            $this->expect(Token::RPAREN);
        }
        // Inline param/result
        $hasSig   = false;
        $params   = [];
        $results  = [];
        $paramIdx = 0; // running index for name capture
        while ($this->peek()->type === Token::LPAREN && in_array($this->peekAhead(1)->value, ['param', 'result'], true)) {
            $hasSig = true;
            $this->consume();
            $kw = $this->consume()->value;
            if ($kw === 'param') {
                $paramName = null;
                if ($this->peek()->type === Token::ID) {
                    $paramName = $this->consume()->value;
                }
                $prevCount = count($params);
                $this->consumeValTypesInto($params);
                $added = count($params) - $prevCount;
                if ($captureParamNames && $paramName !== null) {
                    $this->localNames[$paramName] = $paramIdx;
                }
                $paramIdx += $added;
            } else {
                $this->consumeValTypesInto($results);
            }
            $this->expect(Token::RPAREN);
        }
        if ($typeIdx >= 0) {
            return $typeIdx;
        }
        if ($hasSig) {
            return $this->findOrCreateType(new FuncType($params, $results));
        }
        return $this->findOrCreateType(new FuncType([], []));
    }

    private function findOrCreateType(FuncType $ft): int
    {
        foreach ($this->mod->types as $i => $t) {
            if ($t->equals($ft)) {
                return $i;
            }
        }
        $idx = count($this->mod->types);
        $this->mod->types[] = $ft;
        return $idx;
    }

    private function parseLimits(): array
    {
        $min = (int)$this->expect(Token::INT)->value;
        $max = null;
        if ($this->peek()->type === Token::INT) {
            $max = (int)$this->consume()->value;
        }
        return [$min, $max];
    }

    private function parseGlobalType(): array
    {
        if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'mut') {
            $this->consume();
            $this->consume();
            $t = ValType::fromString($this->expectKeyword(null));
            $this->expect(Token::RPAREN);
            return [$t, true];
        }
        $t = ValType::fromString($this->expectKeyword(null));
        return [$t, false];
    }

    /** Parse a constant expression returning a WasmValue */
    private function parseConstExpr(): WasmValue
    {
        if ($this->peek()->type === Token::LPAREN) {
            $this->consume();
            $op  = $this->expectKeyword(null);
            $val = $this->parseConstValue($op);
            $this->expect(Token::RPAREN);
            return $val;
        }
        $op  = $this->expectKeyword(null);
        return $this->parseConstValue($op);
    }

    private function parseConstExprVal(): int|float
    {
        $w = $this->parseConstExpr();
        return $w->value;
    }

    private function parseConstValue(string $op): WasmValue
    {
        return match ($op) {
            'i32.const' => WasmValue::i32((int)$this->consumeNumeric()),
            'i64.const' => WasmValue::i64((int)$this->consumeNumeric()),
            'f32.const' => WasmValue::f32($this->consumeF32Float()),
            'f64.const' => WasmValue::f64($this->consumeF64Float()),
            default     => throw new WasmError("Not a const expr: $op"),
        };
    }

    // -------------------------------------------------------------------------
    // Instruction parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a sequence of instructions until RPAREN (end of func / block body).
     * Returns a nested instruction tree. Each instruction is:
     *   ['op' => string, 'args' => mixed[], 'children' => [[]]] (for folded form)
     */
    private function parseInstrSeq(?FuncType $funcType = null): array
    {
        $instrs = [];
        while (true) {
            $tok = $this->peek();
            if ($tok->type === Token::RPAREN || $tok->type === Token::EOF) {
                break;
            }
            if ($tok->type === Token::LPAREN) {
                $folded = $this->parseFoldedInstr();
                if ($folded === null) {
                    // Not an instruction S-expr (e.g. (then ...) handled by caller) — stop
                    break;
                }
                foreach ($this->flatten($folded) as $i) {
                    $instrs[] = $i;
                }
            } elseif ($tok->type === Token::KEYWORD) {
                $instr = $this->parseFlatInstr();
                if ($instr === null) {
                    // Non-instruction keyword (else/end handled by caller) — stop
                    break;
                }
                $instrs[] = $instr;
            } else {
                break;
            }
        }
        return $instrs;
    }

    /** Parse one flat (non-folded) instruction */
    private function parseFlatInstr(): ?array
    {
        $op = $this->peek()->value;
        if (!$this->isInstrKeyword((string)$op)) {
            return null;
        }
        $this->consume();
        return $this->buildInstr((string)$op);
    }

    /** Parse one folded (S-expr) instruction: (op children... args?) */
    private function parseFoldedInstr(): ?array
    {
        if ($this->peek()->type !== Token::LPAREN) {
            return null;
        }
        $saved = $this->pos;
        $this->consume(); // (
        $tok = $this->peek();
        if (!$this->isInstrKeyword((string)$tok->value)) {
            $this->pos = $saved;
            return null;
        }
        $op = $this->consume()->value;
        // parse immediate args first (numbers/ids/types that precede sub-exprs)
        $instr = $this->buildInstrFolded((string)$op);
        $this->expect(Token::RPAREN);
        return $instr;
    }

    /**
     * Build an instruction node from an opcode string.
     * For flat instructions: reads immediates only.
     */
    private function buildInstr(string $op, bool $folded = false): array
    {
        $i = ['op' => $op, 'imm' => [], 'children' => []];
        switch ($op) {
            case 'block': case 'loop': case 'if':
                // optional label (e.g. block $label, loop $l)
                $label = null;
                if ($this->peek()->type === Token::ID) {
                    $label = (string)$this->consume()->value;
                }
                $blockType = $this->parseBlockType();
                $i['imm'][] = $blockType;
                $i['label'] = $label;
                // In folded if, (then ...) / (else ...) are handled by buildInstrFolded.
                // Condition sub-exprs become children, so don't parse them here.
                if ($op === 'if' && $folded) {
                    $i['then'] = [];
                    $i['else'] = [];
                    break;
                }
                $thenInstrs = $this->parseInstrSeq();
                $elseInstrs = [];
                if ($op === 'if' && $this->peek()->type === Token::KEYWORD && $this->peek()->value === 'else') {
                    $this->consume(); // else
                    // optional label
                    if ($this->peek()->type === Token::ID) {
                        $this->consume();
                    }
                    $elseInstrs = $this->parseInstrSeq();
                }
                if ($this->peek()->type === Token::KEYWORD && $this->peek()->value === 'end') {
                    $this->consume();
                    if ($this->peek()->type === Token::ID) {
                        $this->consume(); // end label
                    }
                }
                $i['then'] = $thenInstrs;
                $i['else'] = $elseInstrs;
                break;
            case 'end':
                if ($this->peek()->type === Token::ID) {
                    $this->consume();
                }
                break;
            case 'else':
                if ($this->peek()->type === Token::ID) {
                    $this->consume();
                }
                break;
            case 'br': case 'br_if':
                $i['imm'][] = $this->parseLabelIdx();
                break;
            case 'br_table':
                $labels = [];
                while ($this->peek()->type === Token::INT || $this->peek()->type === Token::ID) {
                    $labels[] = $this->parseLabelIdx();
                }
                $i['imm'] = $labels;
                break;
            case 'call':
                $i['imm'][] = $this->resolveFuncIdx();
                break;
            case 'call_indirect':
                // Optional table index before type use: call_indirect $t (type $check) OR call_indirect N (type ..)
                $tableIdx = 0;
                if ($this->peek()->type === Token::INT) {
                    $tableIdx = (int)$this->consume()->value;
                } elseif ($this->peek()->type === Token::ID) {
                    $tableIdx = $this->resolveTableIdx();
                }
                $typeIdx = $this->resolveTypeUse();
                // Also handle (table ...) form after type use
                if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'table') {
                    $this->consume();
                    $this->consume();
                    $tableIdx = $this->resolveTableIdx();
                    $this->expect(Token::RPAREN);
                }
                $i['imm'] = [$typeIdx, $tableIdx];
                break;
            case 'local.get': case 'local.set': case 'local.tee':
                $i['imm'][] = $this->resolveLocalIdx();
                break;
            case 'global.get': case 'global.set':
                $i['imm'][] = $this->resolveGlobalIdx();
                break;
            case 'i32.const':
                $i['imm'][] = WasmValue::mask32((int)$this->consumeNumeric());
                break;
            case 'i64.const':
                $i['imm'][] = (int)$this->consumeNumeric();
                break;
            case 'f32.const':
                $i['imm'][] = WasmValue::canonF32($this->consumeF32Float());
                break;
            case 'f64.const':
                $i['imm'][] = $this->consumeF64Float();
                break;
            case 'select':
                // optional type annotation
                if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'result') {
                    $this->consume();
                    $this->consume();
                    while ($this->peek()->type === Token::KEYWORD && $this->isValType($this->peek()->value)) {
                        $this->consume();
                    }
                    $this->expect(Token::RPAREN);
                }
                break;
            case 'table.get': case 'table.set': case 'table.size': case 'table.grow':
            case 'table.fill': case 'table.copy': case 'table.init': case 'elem.drop':
                // optional table index
                if ($this->peek()->type === Token::INT || $this->peek()->type === Token::ID) {
                    $i['imm'][] = $this->resolveTableIdx();
                }
                // table.copy and table.init take a second index
                if (($op === 'table.copy' || $op === 'table.init') &&
                    ($this->peek()->type === Token::INT || $this->peek()->type === Token::ID)) {
                    $i['imm'][] = $this->resolveTableIdx();
                }
                break;
            case 'ref.func':
                // function index immediate
                if ($this->peek()->type === Token::INT || $this->peek()->type === Token::ID) {
                    $i['imm'][] = $this->resolveFuncIdx();
                }
                break;
            case 'ref.null':
                // heap type: func, extern, $id, or keyword
                if ($this->peek()->type === Token::KEYWORD || $this->peek()->type === Token::ID) {
                    $i['imm'][] = (string)$this->consume()->value;
                }
                break;
            case 'ref.is_null': case 'ref.as_non_null':
                break; // no immediates
            default:
                // Memory instructions: offset= align= immediates
                if ($this->isMemInstr($op)) {
                    $this->memoryUsed = true;
                    $memarg = $this->parseMemArg();
                    $i['imm'] = [$memarg['offset'], $memarg['align']];
                }
                if ($op === 'memory.size' || $op === 'memory.grow') {
                    $this->memoryUsed = true;
                }
                // All others: no immediates
                break;
        }
        return $i;
    }

    /**
     * Build a folded instruction (everything between the outer parens).
     * Sub-expressions become children of this instruction.
     */
    private function buildInstrFolded(string $op): array
    {
        $i = $this->buildInstr($op, true);
        // After immediates, parse sub-expressions (operand folds)
        while ($this->peek()->type === Token::LPAREN && $this->isInstrKeyword((string)$this->peekAhead(1)->value)) {
            $child = $this->parseFoldedInstr();
            if ($child !== null) {
                $i['children'][] = $child;
            }
        }
        // For if: then/else sub-exprs
        if ($op === 'if') {
            // folded if form: (if ... (then ...) (else ...))
            if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'then') {
                $this->consume();
                $this->consume();
                $i['then'] = $this->parseInstrSeq();
                $this->expect(Token::RPAREN);
            }
            if ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'else') {
                $this->consume();
                $this->consume();
                $i['else'] = $this->parseInstrSeq();
                $this->expect(Token::RPAREN);
            }
        }
        return $i;
    }

    /**
     * Flatten a folded instruction tree to a flat list.
     * Children come before the parent (operand order).
     */
    private function flatten(array $instr): array
    {
        $result = [];
        foreach ($instr['children'] as $child) {
            foreach ($this->flatten($child) as $f) {
                $result[] = $f;
            }
        }
        $flat = ['op' => $instr['op'], 'imm' => $instr['imm']];
        if (isset($instr['label'])) {
            $flat['label'] = $instr['label'];
        }
        if (isset($instr['then'])) {
            $flat['then'] = $instr['then'];
            $flat['else'] = $instr['else'] ?? [];
        }
        $result[] = $flat;
        return $result;
    }

    /**
     * Second pass: compile tree-based instruction list to a flat bytecode array.
     *
     * IP layout for block:
     *   [blockIp]              = ['block', blockType, endIp]
     *   [blockIp+1 .. endIp-1] = body
     *   [endIp]                = ['end']
     *
     * IP layout for loop:
     *   [loopIp]               = ['loop', blockType, contIp, endIp]
     *                            contIp = loopIp+1 (first body instr)
     *   [contIp .. endIp-1]    = body
     *   [endIp]                = ['end']
     *
     * IP layout for if (with else):
     *   [ifIp]                 = ['if', blockType, elseIp, endIp]
     *   [ifIp+1 .. elseIp-1]  = then-body
     *   [elseIp]               = ['else', endIp]
     *   [elseIp+1 .. endIp-1] = else-body
     *   [endIp]                = ['end']
     *
     * IP layout for if (no else):
     *   [ifIp]                 = ['if', blockType, endIp, endIp]  (elseIp==endIp)
     *   [ifIp+1 .. endIp-1]   = then-body
     *   [endIp]                = ['end']
     */
    /**
     * @param int $baseOffset Absolute IP offset of this code array's start within the function.
     *   Must be passed so nested blocks can compute correct absolute endIps.
     */
    private function compileInstr(array $instrs, int $baseOffset = 0): array
    {
        $code = [];
        foreach ($instrs as $instr) {
            $op = $instr['op'];
            switch ($op) {
                case 'block': {
                    $label   = $instr['label'] ?? null;
                    array_unshift($this->compileLabelStack, $label);
                    $blockIp   = $baseOffset + count($code);         // absolute IP of block instr
                    $blockCode = $this->compileInstr($instr['then'] ?? [], $blockIp + 1);
                    array_shift($this->compileLabelStack);
                    $endIp     = $blockIp + 1 + count($blockCode);   // absolute IP of 'end'
                    $code[]    = ['block', $instr['imm'][0] ?? null, $endIp];
                    foreach ($blockCode as $c) { $code[] = $c; }
                    $code[]    = ['end']; // at $endIp
                    break;
                }
                case 'loop': {
                    $label   = $instr['label'] ?? null;
                    array_unshift($this->compileLabelStack, $label);
                    $loopIp   = $baseOffset + count($code);          // absolute IP of loop instr
                    $contIp   = $loopIp + 1;                         // first body instr (absolute)
                    $loopCode = $this->compileInstr($instr['then'] ?? [], $contIp);
                    array_shift($this->compileLabelStack);
                    $endIp    = $loopIp + 1 + count($loopCode);      // absolute IP of 'end'
                    $code[]   = ['loop', $instr['imm'][0] ?? null, $contIp, $endIp];
                    foreach ($loopCode as $c) { $code[] = $c; }
                    $code[]   = ['end']; // at $endIp
                    break;
                }
                case 'if': {
                    $label = $instr['label'] ?? null;
                    array_unshift($this->compileLabelStack, $label);
                    $ifIp     = $baseOffset + count($code);          // absolute IP of if instr
                    $thenCode = $this->compileInstr($instr['then'] ?? [], $ifIp + 1);
                    $hasElse  = !empty($instr['else']);

                    if ($hasElse) {
                        $elseIp   = $ifIp + 1 + count($thenCode);   // absolute IP of 'else' instr
                        $elseCode = $this->compileInstr($instr['else'] ?? [], $elseIp + 1);
                        $endIp    = $elseIp + 1 + count($elseCode);  // absolute IP of 'end' instr
                        array_shift($this->compileLabelStack);
                        $code[] = ['if', $instr['imm'][0] ?? null, $elseIp, $endIp];
                        foreach ($thenCode as $c) { $code[] = $c; }
                        $code[] = ['else', $endIp];
                        foreach ($elseCode as $c) { $code[] = $c; }
                    } else {
                        $endIp  = $ifIp + 1 + count($thenCode);     // absolute IP of 'end' instr
                        array_shift($this->compileLabelStack);
                        $code[] = ['if', $instr['imm'][0] ?? null, $endIp, $endIp]; // no else
                        foreach ($thenCode as $c) { $code[] = $c; }
                    }
                    $code[] = ['end']; // at $endIp
                    break;
                }
                default: {
                    $imm = $instr['imm'];
                    // Resolve string label names to numeric depths for br/br_if/br_table
                    if (($op === 'br' || $op === 'br_if') && isset($imm[0]) && is_string($imm[0])) {
                        $imm[0] = $this->resolveLabelDepth($imm[0]);
                    } elseif ($op === 'br_table') {
                        $imm = array_map(
                            fn($l) => is_string($l) ? $this->resolveLabelDepth($l) : $l,
                            $imm
                        );
                    }
                    $code[] = [$op, ...$imm];
                    break;
                }
            }
        }
        return $code;
    }

    /** Resolve a label name to a numeric branch depth (0 = innermost). */
    private function resolveLabelDepth(string $label): int
    {
        foreach ($this->compileLabelStack as $depth => $name) {
            if ($name === $label) {
                return $depth;
            }
        }
        throw new WasmError("Unknown label: $label");
    }

    private function parseBlockType(): ?FuncType
    {
        $params  = [];
        $results = [];
        // Consume all block type annotations: (type ...) (param ...) (result ...)
        while ($this->peek()->type === Token::LPAREN) {
            $kw = (string)$this->peekAhead(1)->value;
            if ($kw === 'result') {
                $this->consume(); $this->consume();
                $this->consumeValTypesInto($results);
                $this->expect(Token::RPAREN);
            } elseif ($kw === 'param') {
                $this->consume(); $this->consume();
                if ($this->peek()->type === Token::ID) $this->consume(); // optional name
                $this->consumeValTypesInto($params);
                $this->expect(Token::RPAREN);
            } elseif ($kw === 'type') {
                $this->consume(); $this->consume();
                $typeIdx = $this->resolveTypeIdx();
                $this->expect(Token::RPAREN);
                if (isset($this->mod->types[$typeIdx])) {
                    $ft = $this->mod->types[$typeIdx];
                    $params  = $ft->params;
                    $results = $ft->results;
                }
            } else {
                break;
            }
        }
        // Bare valtype (e.g. block i32 ...)
        if (empty($results) && $this->peek()->type === Token::KEYWORD && $this->isValType($this->peek()->value)) {
            $results[] = ValType::fromString($this->consume()->value);
        }
        return ($params !== [] || $results !== []) ? new FuncType($params, $results) : null;
    }

    private function parseMemArg(): array
    {
        $offset = 0;
        $align  = 0;
        if ($this->peek()->type === Token::KEYWORD && str_starts_with((string)$this->peek()->value, 'offset=')) {
            $tok    = $this->consume()->value;
            $offset = (int)hexdec(str_replace('offset=', '', (string)$tok)) ?: (int)substr((string)$tok, 7);
            // handle hex and decimal
            $raw    = substr((string)$tok, 7);
            $offset = str_starts_with($raw, '0x') ? hexdec($raw) : (int)$raw;
        }
        if ($this->peek()->type === Token::KEYWORD && str_starts_with((string)$this->peek()->value, 'align=')) {
            $tok   = $this->consume()->value;
            $raw   = substr((string)$tok, 6);
            $align = str_starts_with($raw, '0x') ? hexdec($raw) : (int)$raw;
        }
        return ['offset' => $offset, 'align' => $align];
    }

    // -------------------------------------------------------------------------
    // Index resolution
    // -------------------------------------------------------------------------

    private function resolveTypeIdx(): int
    {
        $tok = $this->consume();
        if ($tok->type === Token::ID) {
            return $this->typeIds[(string)$tok->value] ?? throw new WasmError("Unknown type id: {$tok->value}");
        }
        return (int)$tok->value;
    }

    private function resolveFuncIdx(): int
    {
        $tok = $this->peek();
        if ($tok->type === Token::ID) {
            $this->consume();
            return $this->funcIds[(string)$tok->value] ?? throw new WasmError("Unknown func id: {$tok->value}");
        }
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        throw new WasmError("Expected func index at line {$tok->line}");
    }

    private function resolveTableIdx(): int
    {
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        if ($tok->type === Token::ID) {
            $this->consume();
            return $this->tableIds[(string)$tok->value] ?? 0;
        }
        return 0;
    }

    private function resolveMemIdx(): int
    {
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        if ($tok->type === Token::ID) {
            $this->consume();
            return $this->memIds[(string)$tok->value] ?? 0;
        }
        return 0;
    }

    private function resolveGlobalIdx(): int
    {
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        if ($tok->type === Token::ID) {
            $this->consume();
            $total = count(array_filter($this->mod->imports, fn($i) => $i['kind'] === 'global')) + count($this->mod->globals);
            // Search all defined globals by id
            return $this->globalIds[(string)$tok->value] ?? throw new WasmError("Unknown global id: {$tok->value}");
        }
        throw new WasmError("Expected global index");
    }

    private function resolveLocalIdx(): int
    {
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        if ($tok->type === Token::ID) {
            $this->consume();
            $name = (string)$tok->value;
            if (!isset($this->localNames[$name])) {
                throw new WasmError("Unknown local variable '$name' at line {$tok->line}");
            }
            return $this->localNames[$name];
        }
        throw new WasmError("Expected local index at line {$tok->line}");
    }

    private function parseLabelIdx(): int|string
    {
        $tok = $this->peek();
        if ($tok->type === Token::INT) {
            $this->consume();
            return (int)$tok->value;
        }
        if ($tok->type === Token::ID) {
            $this->consume();
            return (string)$tok->value;
        }
        throw new WasmError("Expected label index at line {$tok->line}");
    }

    /**
     * Pre-scan the token stream to register all func IDs before full parsing.
     * This enables forward references (e.g. calling $odd before $odd is defined).
     */
    private function preScanFuncIds(): void
    {
        $importedFuncCount = 0;
        $localFuncCount    = 0;
        $n = count($this->tokens);

        // Find 'module' keyword
        $i = 0;
        while ($i < $n && !($this->tokens[$i]->type === Token::KEYWORD && $this->tokens[$i]->value === 'module')) {
            $i++;
        }
        $i++; // skip 'module'
        // skip optional module id
        if ($i < $n && $this->tokens[$i]->type === Token::ID) {
            $i++;
        }

        // Scan top-level module fields
        while ($i < $n && $this->tokens[$i]->type === Token::LPAREN) {
            $kw = $this->tokens[$i + 1] ?? null;
            if (!$kw || $kw->type !== Token::KEYWORD) {
                break;
            }

            // Find end of this S-expr (skip from the opening LPAREN)
            $j = $i + 1;
            $depth = 1;
            while ($j < $n && $depth > 0) {
                if ($this->tokens[$j]->type === Token::LPAREN)       $depth++;
                elseif ($this->tokens[$j]->type === Token::RPAREN)   $depth--;
                $j++;
            }
            // $i..$j-1 is this entire S-expr; $j is the next field

            if ($kw->value === 'import') {
                // Check whether there's a (func ...) inside this import
                for ($k = $i + 2; $k < $j; $k++) {
                    if ($this->tokens[$k]->type === Token::LPAREN
                        && isset($this->tokens[$k + 1])
                        && $this->tokens[$k + 1]->type === Token::KEYWORD
                        && $this->tokens[$k + 1]->value === 'func'
                    ) {
                        $importedFuncCount++;
                        break;
                    }
                }
            } elseif ($kw->value === 'func') {
                $p = $i + 2; // position right after '(' 'func'

                // Detect inline import: (func [id] (import ...) ...)
                $isInlineImport = false;
                for ($k = $p; $k < $j; $k++) {
                    if ($this->tokens[$k]->type === Token::LPAREN
                        && isset($this->tokens[$k + 1])
                        && $this->tokens[$k + 1]->type === Token::KEYWORD
                        && $this->tokens[$k + 1]->value === 'import'
                    ) {
                        $isInlineImport = true;
                        break;
                    }
                    // Stop scanning once we hit (param) or (result) — inline import comes before those
                    if ($this->tokens[$k]->type === Token::LPAREN
                        && isset($this->tokens[$k + 1])
                        && $this->tokens[$k + 1]->type === Token::KEYWORD
                        && in_array($this->tokens[$k + 1]->value, ['param', 'result', 'local'], true)
                    ) {
                        break;
                    }
                }

                $idTok = $this->tokens[$p] ?? null;
                if ($idTok && $idTok->type === Token::ID) {
                    $this->funcIds[(string)$idTok->value] = $importedFuncCount + $localFuncCount;
                }

                if ($isInlineImport) {
                    $importedFuncCount++;
                } else {
                    $localFuncCount++;
                }
            }

            $i = $j;
        }
    }

    /**
     * Pre-scan the token stream to register all type IDs before full parsing.
     * This enables forward references (e.g. using $forward before it is defined).
     */
    private function preScanTypeIds(): void
    {
        $typeCount = 0;
        $n = count($this->tokens);

        $i = 0;
        while ($i < $n && !($this->tokens[$i]->type === Token::KEYWORD && $this->tokens[$i]->value === 'module')) {
            $i++;
        }
        $i++; // skip 'module'
        if ($i < $n && $this->tokens[$i]->type === Token::ID) {
            $i++; // skip optional module id
        }

        while ($i < $n && $this->tokens[$i]->type === Token::LPAREN) {
            $kw = $this->tokens[$i + 1] ?? null;
            if (!$kw || $kw->type !== Token::KEYWORD) {
                break;
            }

            $j = $i + 1;
            $depth = 1;
            while ($j < $n && $depth > 0) {
                if ($this->tokens[$j]->type === Token::LPAREN)     $depth++;
                elseif ($this->tokens[$j]->type === Token::RPAREN) $depth--;
                $j++;
            }

            if ($kw->value === 'type') {
                $p = $i + 2;
                $idTok = $this->tokens[$p] ?? null;
                if ($idTok && $idTok->type === Token::ID) {
                    $this->typeIds[(string)$idTok->value] = $typeCount;
                }
                $typeCount++;
            }

            $i = $j;
        }
    }

    private function resolveImportCounts(): void
    {
        $fc = $gc = $mc = $tc = 0;
        foreach ($this->mod->imports as $imp) {
            match ($imp['kind']) {
                'func'   => $fc++,
                'global' => $gc++,
                'memory' => $mc++,
                'table'  => $tc++,
            };
        }
        $this->mod->importedFuncCount   = $fc;
        $this->mod->importedGlobalCount = $gc;
        $this->mod->importedMemoryCount = $mc;
        $this->mod->importedTableCount  = $tc;
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    private function peek(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(Token::EOF, '', 0);
    }

    private function peekAhead(int $offset): Token
    {
        return $this->tokens[$this->pos + $offset] ?? new Token(Token::EOF, '', 0);
    }

    private function consume(): Token
    {
        return $this->tokens[$this->pos++] ?? new Token(Token::EOF, '', 0);
    }

    private function expect(string $type): Token
    {
        $tok = $this->consume();
        if ($tok->type !== $type) {
            throw new WasmError("Expected $type but got {$tok->type}({$tok->value}) at line {$tok->line}");
        }
        return $tok;
    }

    private function expectKeyword(?string $kw): string
    {
        $tok = $this->consume();
        if ($tok->type !== Token::KEYWORD) {
            throw new WasmError("Expected keyword" . ($kw ? " '$kw'" : '') . " but got {$tok->type}({$tok->value}) at line {$tok->line}");
        }
        if ($kw !== null && $tok->value !== $kw) {
            throw new WasmError("Expected keyword '$kw' but got '{$tok->value}' at line {$tok->line}");
        }
        return (string)$tok->value;
    }

    private function consumeNumeric(): int|float
    {
        $tok = $this->consume();
        if ($tok->type === Token::INT || $tok->type === Token::FLOAT) {
            return $tok->value;
        }
        // e.g. nan, inf already tokenised as FLOAT
        throw new WasmError("Expected number but got {$tok->type}({$tok->value}) at line {$tok->line}");
    }

    /**
     * Consume a float constant in f32 context.
     * Handles nan:0xN keywords by constructing the exact f32 bit pattern.
     */
    private function consumeF32Float(): float
    {
        $tok = $this->peek();
        if ($tok->type === Token::KEYWORD) {
            $kw = (string)$tok->value;
            if (preg_match('/^([+-]?)nan:0x([0-9a-fA-F_]+)$/', $kw, $m)) {
                $this->consume();
                $payload = (int)(hexdec(str_replace('_', '', $m[2])) & 0x7FFFFF);
                $sign    = ($m[1] === '-') ? 0x80000000 : 0;
                $bits32  = $sign | 0x7F800000 | $payload;
                return (float)unpack('f', pack('V', $bits32))[1];
            }
        }
        return (float)$this->consumeNumeric();
    }

    /**
     * Consume a float constant in f64 context.
     * Handles nan:0xN keywords by constructing the exact f64 bit pattern.
     */
    private function consumeF64Float(): float
    {
        $tok = $this->peek();
        if ($tok->type === Token::KEYWORD) {
            $kw = (string)$tok->value;
            if (preg_match('/^([+-]?)nan:0x([0-9a-fA-F_]+)$/', $kw, $m)) {
                $this->consume();
                $payload = hexdec(str_replace('_', '', $m[2])); // up to 52-bit mantissa payload
                $hi32    = ($m[1] === '-' ? 0x80000000 : 0) | 0x7FF00000
                         | (int)(($payload >> 32) & 0xFFFFF);
                $lo32    = (int)($payload & 0xFFFFFFFF);
                return (float)unpack('d', pack('VV', $lo32, (int)$hi32))[1];
            }
        }
        return (float)$this->consumeNumeric();
    }

    private function skipSExpr(): void
    {
        // We already consumed the keyword, skip until matching RPAREN
        $this->skipUntilRParen();
    }

    private function skipUntilRParen(): void
    {
        $depth = 1;
        while ($depth > 0) {
            $tok = $this->consume();
            if ($tok->type === Token::LPAREN) {
                $depth++;
            } elseif ($tok->type === Token::RPAREN) {
                $depth--;
            } elseif ($tok->type === Token::EOF) {
                break;
            }
        }
    }

    private function isValType(string $s): bool
    {
        return in_array($s, ['i32', 'i64', 'f32', 'f64', 'funcref', 'externref'], true);
    }

    /**
     * Skip a (ref ...) reference type, treating it as a ValType::FUNCREF placeholder.
     * Handles forms like: (ref null func), (ref null $t), (ref $t), (ref func), etc.
     */
    private function skipRefType(): void
    {
        $this->consume(); // (
        $this->consume(); // ref
        // consume tokens until matching )
        $depth = 1;
        while ($depth > 0) {
            $t = $this->consume();
            if ($t->type === Token::LPAREN) $depth++;
            elseif ($t->type === Token::RPAREN) $depth--;
            elseif ($t->type === Token::EOF) break;
        }
    }

    /**
     * Consume value types into $types, handling both simple keywords and (ref ...) forms.
     */
    private function consumeValTypesInto(array &$types): void
    {
        while (true) {
            if ($this->peek()->type === Token::KEYWORD && $this->isValType($this->peek()->value)) {
                $types[] = ValType::fromString($this->consume()->value);
            } elseif ($this->peek()->type === Token::LPAREN && $this->peekAhead(1)->value === 'ref') {
                $types[] = ValType::FUNCREF; // placeholder for ref types
                $this->skipRefType();
            } else {
                break;
            }
        }
    }

    private function isMemInstr(string $op): bool
    {
        return (bool)preg_match('/^(?:i32|i64|f32|f64)\.(?:load|store)/', $op);
    }

    private function isInstrKeyword(string $kw): bool
    {
        static $nonInstrKeywords = [
            'module', 'func', 'type', 'import', 'export', 'table', 'memory',
            'global', 'local', 'param', 'result', 'start', 'elem', 'data',
            'mut', 'then', 'else', 'end', 'funcref', 'externref',
            'assert_return', 'assert_trap', 'assert_invalid', 'assert_malformed',
            'assert_unlinkable', 'assert_exhaustion', 'invoke', 'register', 'get',
            'item', 'offset', 'align',
        ];
        if (in_array($kw, $nonInstrKeywords, true)) {
            return false;
        }
        // Any keyword with '.' is likely an instruction (e.g., i32.add)
        if (str_contains($kw, '.')) {
            return true;
        }
        // Control flow keywords
        return in_array($kw, [
            'unreachable', 'nop', 'block', 'loop', 'if', 'br', 'br_if', 'br_table',
            'return', 'call', 'call_indirect', 'drop', 'select', 'ref.null', 'ref.func', 'ref.is_null',
            'memory.size', 'memory.grow', 'memory.init', 'memory.copy', 'memory.fill',
            'table.get', 'table.set', 'table.size', 'table.grow', 'table.fill', 'table.copy', 'table.init',
            'return_call', 'return_call_indirect',
        ], true);
    }
}
