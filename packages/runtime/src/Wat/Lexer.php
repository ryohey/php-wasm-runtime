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
                $hexDigits = substr($hex, 2); // hex digits without 0x prefix
                if (strlen($hexDigits) > 16) {
                    // Too many hex digits to fit in int64; convert exactly to decimal via
                    // BCMath then cast to float (PHP's strtod is correctly rounded).
                    $dec = '0';
                    for ($i = 0, $n = strlen($hexDigits); $i < $n; $i++) {
                        $dec = bcadd(bcmul($dec, '16'), (string)hexdec($hexDigits[$i]));
                    }
                    $fv = (float)$dec;
                    return new Token(Token::FLOAT, $sign < 0 ? -$fv : $fv, $line);
                }
                $digits = str_pad($hexDigits, 16, '0', STR_PAD_LEFT);
                $hi  = (int)hexdec(substr($digits, -16, 8));
                $lo  = (int)hexdec(substr($digits, -8));
                $val = ($hi << 32) | $lo;
                // Apply sign in two's complement.
                // Special case: -0x8000000000000000 overflows PHP int; val is already PHP_INT_MIN
                // which correctly represents -2^63 — skip negation.
                if ($sign < 0 && $val !== PHP_INT_MIN) $val = -$val;
                return new Token(Token::INT, $val, $line);
            }
            // Decimal integer: detect overflow beyond int64 range.
            // Values up to 19 significant digits fit in int64 directly.
            // Values with exactly 20 digits may be valid uint64 (e.g. 18446744073709551615 = -1)
            // — use bcmath two's complement.  Values with 21+ digits exceed uint64 and should
            // be stored as float so that f32/f64.const can approximate them.
            $absStr = ltrim($clean, '+-');
            if (strlen($absStr) >= 20) {
                // Check if the value fits in uint64 (≤ 18446744073709551615 = 2^64-1)
                if (strlen($absStr) === 20
                    && bccomp($absStr, '18446744073709551615') <= 0
                ) {
                    // Compute hi/lo 32-bit halves via bcmath, then combine
                    $neg  = ($clean[0] === '-');
                    $hi   = (int)bcdiv($absStr, '4294967296', 0);
                    $lo   = (int)bcmod($absStr, '4294967296');
                    $val  = ($hi << 32) | $lo;
                    if ($neg) $val = -$val;
                    return new Token(Token::INT, $val, $line);
                }
                // Beyond uint64 range: store as float for f32/f64.const contexts
                return new Token(Token::FLOAT, (float)$clean, $line);
            }
            return new Token(Token::INT, (int)$clean, $line);
        }

        // nan:0xNN  canonical / arithmetic NaN with payload (underscores allowed in hex)
        if (preg_match('/^[+-]?nan(?::0x[0-9a-fA-F_]+)?$/', $word)) {
            if (str_contains($word, ':')) {
                // nan:0xN payload — keep as KEYWORD so the Parser can construct
                // the exact bit pattern in its type context (f32 vs f64).
                return new Token(Token::KEYWORD, $word, $line);
            }
            // Plain nan / -nan: preserve sign bit via explicit byte construction.
            $nan = ($word !== '' && $word[0] === '-')
                ? (float)unpack('d', "\x00\x00\x00\x00\x00\x00\xF8\xFF")[1]
                : NAN;
            return new Token(Token::FLOAT, $nan, $line);
        }
        if (preg_match('/^[+-]?inf$/', $word)) {
            $v = $word[0] === '-' ? -INF : INF;
            return new Token(Token::FLOAT, $v, $line);
        }

        // Try float (includes hex floats like 0x1p10)
        if (preg_match('/^[+-]?(?:0x[0-9a-fA-F_]+(?:\.[0-9a-fA-F_]*)?(?:[pP][+-]?[0-9_]+)?|[0-9][0-9_]*(?:\.[0-9_]*)?(?:[eE][+-]?[0-9_]+)?)$/', $word)) {
            $clean = str_replace('_', '', $word);
            // PHP's (float) cast does not support hex float literals (0x1p-149 etc.)
            // so we parse them manually.
            if (preg_match('/^[+-]?0x/i', $clean)) {
                return new Token(Token::FLOAT, self::parseHexFloat($clean), $line);
            }
            return new Token(Token::FLOAT, (float)$clean, $line);
        }

        // Otherwise keyword
        return new Token(Token::KEYWORD, $word, $line);
    }

    private static function parseHexFloat(string $s): float
    {
        $sign = 1.0;
        if ($s !== '' && $s[0] === '-') {
            $sign = -1.0;
            $s    = substr($s, 1);
        } elseif ($s !== '' && $s[0] === '+') {
            $s = substr($s, 1);
        }
        // strip 0x prefix
        $s = substr($s, 2);

        // split on p/P for exponent
        $parts = preg_split('/[pP]/', $s, 2);
        $mantissaStr = $parts[0];
        $bexp        = isset($parts[1]) ? (int)$parts[1] : 0;

        $dotPos = strpos($mantissaStr, '.');
        if ($dotPos === false) {
            $intHex  = $mantissaStr;
            $fracHex = '';
        } else {
            $intHex  = substr($mantissaStr, 0, $dotPos);
            $fracHex = substr($mantissaStr, $dotPos + 1);
        }

        $fracLen = strlen($fracHex);

        // Fast path: fracLen ≤ 13 means the fractional part fits exactly in a f64
        // mantissa (52 bits = 13 hex digits), so no precision loss occurs.
        if ($fracLen <= 13) {
            $mantissa = ($intHex !== '') ? (float)hexdec($intHex) : 0.0;
            if ($fracHex !== '') {
                $mantissa += (float)hexdec($fracHex) / pow(16.0, $fracLen);
            }
            return $sign * $mantissa * pow(2.0, $bexp);
        }

        // Long fracHex (>13 hex digits): use BCMath to avoid double-rounding errors.
        // The exact value is M * 2^E where M = intMant * 16^fracLen + fracMant (integer)
        // and E = bexp - 4 * fracLen.  PHP's strtod is correctly rounded, so converting
        // M * 2^E to a decimal string and casting to float gives the right result.
        $E       = $bexp - 4 * $fracLen;
        $hexStr  = ($intHex !== '' ? $intHex : '0') . $fracHex;
        $M       = '0';
        for ($i = 0, $n = strlen($hexStr); $i < $n; $i++) {
            $M = bcadd(bcmul($M, '16'), (string)hexdec($hexStr[$i]));
        }
        if ($M === '0') {
            return 0.0;
        }
        return $sign * self::bcLdexp($M, $E);
    }

    /**
     * Return (float)(M * 2^E), correctly rounded.
     * M is a positive BCMath decimal integer string; E is any integer.
     */
    private static function bcLdexp(string $M, int $E): float
    {
        if ($E >= 0) {
            // M * 2^E is an exact integer; PHP's strtod handles arbitrarily large values.
            return (float)bcmul($M, bcpow('2', (string)$E));
        }
        // E < 0: represent M / 2^(-E) as a decimal string with enough precision.
        // f64 needs ~17 significant decimal digits; add 25 guard digits.
        $scale     = -$E;
        $decPlaces = (int)ceil($scale * 0.30103) + 25;
        $shifted   = bcmul($M, bcpow('10', (string)$decPlaces));
        $divisor   = bcpow('2', (string)$scale);
        $quo       = bcdiv($shifted, $divisor, 0);
        $q         = str_pad($quo, $decPlaces + 1, '0', STR_PAD_LEFT);
        $ip        = substr($q, 0, strlen($q) - $decPlaces);
        $fp        = substr($q, -$decPlaces);
        $decStr    = ($ip !== '' ? $ip : '0') . '.' . $fp;
        return (float)$decStr;
    }

    private function isSymStart(string $ch): bool
    {
        // Any printable ASCII except whitespace and reserved chars: '(', ')', '"', ';'
        $o = ord($ch);
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }

    private function isSymChar(string $ch): bool
    {
        // WAT idchar: printable ASCII except whitespace, '(', ')', '"', ';'
        $o = ord($ch);
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }

    private function isIdChar(string $ch): bool
    {
        // Same set as idchar per WebAssembly spec
        $o = ord($ch);
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }
}
