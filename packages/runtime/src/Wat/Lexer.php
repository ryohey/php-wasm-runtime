<?php

declare(strict_types=1);

namespace WasmRuntime\Wat;

use WasmRuntime\WasmError;

final class Lexer
{
    private int $pos  = 0;
    private int $line = 1;
    private int $len;

    public function __construct(private readonly string $src)
    {
        $this->len = strlen($src);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $tokens = [];
        while (true) {
            $tok = $this->next();
            $tokens[] = $tok;
            if ($tok->type === Token::EOF) {
                break;
            }
        }
        return $tokens;
    }

    private function next(): Token
    {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->len) {
            return new Token(Token::EOF, '', $this->line);
        }
        $line = $this->line;
        $ch   = $this->src[$this->pos];

        if ($ch === '(') {
            $this->pos++;
            return new Token(Token::LPAREN, '(', $line);
        }
        if ($ch === ')') {
            $this->pos++;
            return new Token(Token::RPAREN, ')', $line);
        }
        if ($ch === '"') {
            return $this->readString($line);
        }
        if ($ch === '$') {
            return $this->readId($line);
        }

        // number (integer or float), or keyword
        if ($this->isSymStart($ch)) {
            return $this->readAtom($line);
        }

        throw new WasmError("Unexpected character '$ch' at line $line");
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $this->pos++;
            } elseif ($ch === "\n") {
                $this->pos++;
                $this->line++;
            } elseif ($ch === ';' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === ';') {
                // Line comment
                while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
                    $this->pos++;
                }
            } elseif ($ch === '(' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === ';') {
                // Block comment (may be nested)
                $this->pos += 2;
                $depth = 1;
                while ($this->pos < $this->len && $depth > 0) {
                    if ($this->src[$this->pos] === "\n") {
                        $this->line++;
                        $this->pos++;
                    } elseif ($this->pos + 1 < $this->len && $this->src[$this->pos] === '(' && $this->src[$this->pos + 1] === ';') {
                        $depth++;
                        $this->pos += 2;
                    } elseif ($this->pos + 1 < $this->len && $this->src[$this->pos] === ';' && $this->src[$this->pos + 1] === ')') {
                        $depth--;
                        $this->pos += 2;
                    } else {
                        $this->pos++;
                    }
                }
            } else {
                break;
            }
        }
    }

    private function readString(int $line): Token
    {
        $this->pos++; // skip opening "
        $buf = '';
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '"') {
                $this->pos++;
                return new Token(Token::STRING, $buf, $line);
            }
            if ($ch === '\\') {
                $this->pos++;
                $esc = $this->src[$this->pos] ?? '';
                $buf .= match ($esc) {
                    'n'  => "\n",
                    't'  => "\t",
                    'r'  => "\r",
                    '"'  => '"',
                    '\'' => "'",
                    '\\' => '\\',
                    '0'  => "\0",
                    default => $this->readHexEscape($esc),
                };
                $this->pos++;
            } else {
                $buf .= $ch;
                $this->pos++;
            }
        }
        throw new WasmError("Unterminated string at line $line");
    }

    private function readHexEscape(string $first): string
    {
        $second = $this->src[++$this->pos] ?? '';
        return chr(hexdec($first . $second));
    }

    private function readId(int $line): Token
    {
        $start = $this->pos;
        $this->pos++; // skip $
        while ($this->pos < $this->len && $this->isIdChar($this->src[$this->pos])) {
            $this->pos++;
        }
        return new Token(Token::ID, substr($this->src, $start, $this->pos - $start), $line);
    }

    private function readAtom(int $line): Token
    {
        $start = $this->pos;
        while ($this->pos < $this->len && $this->isSymChar($this->src[$this->pos])) {
            $this->pos++;
        }
        $word = substr($this->src, $start, $this->pos - $start);
        return $this->classifyAtom($word, $line);
    }

    private function classifyAtom(string $word, int $line): Token
    {
        // Try integer
        if (preg_match('/^[+-]?(?:0x[0-9a-fA-F_]+|[0-9][0-9_]*)$/', $word)) {
            $clean = str_replace('_', '', $word);
            if (str_starts_with($clean, '0x') || str_starts_with($clean, '-0x') || str_starts_with($clean, '+0x')) {
                $sign = 1;
                $hex  = $clean;
                if ($clean[0] === '-') {
                    $sign = -1;
                    $hex  = substr($clean, 1);
                } elseif ($clean[0] === '+') {
                    $hex = substr($clean, 1);
                }
                $hexDigits = substr($hex, 2);
                if (strlen($hexDigits) > 16) {
                    // Too many hex digits for int64; convert via BCMath then to float
                    $dec = '0';
                    for ($i = 0, $n = strlen($hexDigits); $i < $n; $i++) {
                        $dec = bcadd(bcmul($dec, '16'), (string)hexdec($hexDigits[$i]));
                    }
                    $fv = (float)$dec;
                    return new Token(Token::FLOAT, $sign < 0 ? -$fv : $fv, $line);
                }
                // Split into two 32-bit halves to avoid float overflow from hexdec()
                $digits = str_pad($hexDigits, 16, '0', STR_PAD_LEFT);
                $hi  = (int)hexdec(substr($digits, 0, 8));
                $lo  = (int)hexdec(substr($digits, 8));
                $val = ($hi << 32) | $lo;
                if ($sign < 0 && $val !== PHP_INT_MIN) $val = -$val;
                return new Token(Token::INT, $val, $line);
            }
            return new Token(Token::INT, (int)$clean, $line);
        }

        // nan:0xNN  canonical / arithmetic NaN with payload
        if (preg_match('/^[+-]?nan(?::0x[0-9a-fA-F]+)?$/', $word)) {
            return new Token(Token::FLOAT, NAN, $line);
        }
        if (preg_match('/^[+-]?inf$/', $word)) {
            $v = $word[0] === '-' ? -INF : INF;
            return new Token(Token::FLOAT, $v, $line);
        }

        // Try float (includes hex floats like 0x1p10)
        if (preg_match('/^[+-]?(?:0x[0-9a-fA-F_]+(?:\.[0-9a-fA-F_]*)?(?:[pP][+-]?[0-9]+)?|[0-9][0-9_]*(?:\.[0-9_]*)?(?:[eE][+-]?[0-9]+)?)$/', $word)) {
            $clean = str_replace('_', '', $word);
            return new Token(Token::FLOAT, (float)$clean, $line);
        }

        // Otherwise keyword
        return new Token(Token::KEYWORD, $word, $line);
    }

    private function isSymStart(string $ch): bool
    {
        return ctype_alnum($ch) || $ch === '_' || $ch === '-' || $ch === '+' || $ch === '.' || $ch === ':';
    }

    private function isSymChar(string $ch): bool
    {
        return ctype_alnum($ch) || in_array($ch, ['_', '-', '+', '.', ':', '/', '!', '#', '?', '|', '&', '~', '=', '<', '>'], true);
    }

    private function isIdChar(string $ch): bool
    {
        return ctype_alnum($ch) || in_array($ch, ['_', '-', '+', '.', ':', '/', '!', '#', '?', '|', '&', '~', '=', '<', '>'], true);
    }
}
