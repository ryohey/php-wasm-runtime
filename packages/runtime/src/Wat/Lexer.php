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
                // Line comment: terminated by LF, CR, or CRLF
                while ($this->pos < $this->len && $this->src[$this->pos] !== "\n" && $this->src[$this->pos] !== "\r") {
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
                // WAT spec: tokens must be separated by whitespace; two strings adjacent is invalid
                if ($this->pos < $this->len && $this->src[$this->pos] === '"') {
                    throw new WasmError("unexpected token at line $line");
                }
                return new Token(Token::STRING, $buf, $line);
            }
            if ($ch === '\\') {
                $this->pos++;
                $esc = $this->src[$this->pos] ?? '';
                if ($esc === 'u' && ($this->pos + 1) < $this->len && $this->src[$this->pos + 1] === '{') {
                    // Unicode escape: \u{NNNN}
                    $this->pos += 2; // skip 'u{'
                    $hexStr = '';
                    while ($this->pos < $this->len && $this->src[$this->pos] !== '}') {
                        $hexStr .= $this->src[$this->pos++];
                    }
                    if ($this->pos < $this->len && $this->src[$this->pos] === '}') {
                        $this->pos++; // skip '}'
                    }
                    $codePoint = hexdec($hexStr);
                    $buf .= mb_chr((int)$codePoint, 'UTF-8');
                } else {
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
                }
            } else {
                // WAT spec: string chars must be printable (0x20-0x7E) or multi-byte UTF-8
                $ord = ord($ch);
                if ($ord < 0x20 || $ord === 0x7F) {
                    throw new WasmError("illegal control character in string at line $line");
                }
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
        // Quoted identifier: $"..." — read as a string and return as ID
        if ($this->pos < $this->len && $this->src[$this->pos] === '"') {
            $strTok = $this->readString($line);
            $content = (string)$strTok->value;
            if ($content === '') {
                throw new WasmError("empty identifier at line $line");
            }
            // Validate UTF-8 encoding
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new WasmError("malformed UTF-8 encoding in identifier at line $line");
            }
            // Quoted identifiers must be separated from following tokens by whitespace
            if ($this->pos < $this->len) {
                $next = $this->src[$this->pos];
                if ($next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r"
                    && $next !== ')' && $next !== '(' && $next !== ';') {
                    throw new WasmError("unexpected token at line $line");
                }
            }
            return new Token(Token::ID, '$' . $content, $line);
        }
        while ($this->pos < $this->len && $this->isIdChar($this->src[$this->pos])) {
            $this->pos++;
        }
        $id = substr($this->src, $start, $this->pos - $start);
        // Empty identifier: just "$" with no following chars
        if ($id === '$') {
            throw new WasmError("empty identifier at line $line");
        }
        // WAT spec: ID tokens must be separated from strings by whitespace
        if ($this->pos < $this->len && $this->src[$this->pos] === '"') {
            throw new WasmError("unexpected token at line $line");
        }
        return new Token(Token::ID, $id, $line);
    }

    private function readAtom(int $line): Token
    {
        $start = $this->pos;
        while ($this->pos < $this->len && $this->isSymChar($this->src[$this->pos])) {
            $this->pos++;
        }
        $word = substr($this->src, $start, $this->pos - $start);
        // WAT spec: keyword/number tokens must be separated from strings by whitespace
        if ($this->pos < $this->len && $this->src[$this->pos] === '"') {
            throw new WasmError("unexpected token at line $line");
        }
        return $this->classifyAtom($word, $line);
    }

    private function classifyAtom(string $word, int $line): Token
    {
        // Validate underscore placement before any numeric classification.
        // Per the WAT spec, underscores may only appear as digit separators:
        // they cannot appear at the start/end of the digit string, consecutively,
        // or immediately after the hex '0x' prefix.
        if (str_contains($word, '_')) {
            $s = $word;
            // strip sign
            if ($s !== '' && ($s[0] === '+' || $s[0] === '-')) {
                $s = substr($s, 1);
            }
            $isHex = str_starts_with($s, '0x') || str_starts_with($s, '0X');
            if ($s === '' || $s[0] === '_'          // leading underscore
                || str_ends_with($s, '_')            // trailing underscore
                || str_contains($s, '__')            // consecutive underscores
                || str_starts_with($s, '0x_')        // underscore right after 0x
                || str_starts_with($s, '0X_')
                || str_contains($s, '0_x')           // underscore between 0 and x
                || str_contains($s, '0_X')
                || preg_match('/_\.|\._/', $s)       // underscore adjacent to .
                || ($isHex
                    ? preg_match('/_[pP]|[pP]_|[pP][+-]_|_[+-]/', $s) // hex: p/P is exponent
                    : preg_match('/_[eE]|[eE]_|[eE][+-]_|_[+-]/', $s) // decimal: e/E is exponent
                )
            ) {
                return new Token(Token::KEYWORD, $word, $line);
            }
        }

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
                if ($sign < 0 && $val !== PHP_INT_MIN) {
                    // If val < 0 (high bit set), the unsigned value > 2^63,
                    // so -unsigned would be < -2^63 (out of i64 range).
                    if ($val < 0) {
                        // Use BCMath to compute exact decimal for a useful float value
                        $dec = '0';
                        for ($i = 0, $n = strlen($hexDigits); $i < $n; $i++) {
                            $dec = bcadd(bcmul($dec, '16'), (string)hexdec($hexDigits[$i]));
                        }
                        return new Token(Token::FLOAT, -(float)$dec, $line);
                    }
                    $val = -$val;
                }
                return new Token(Token::INT, $val, $line);
            }
            // Decimal integer: detect overflow beyond int64 range.
            // Values up to 19 significant digits fit in int64 directly.
            // Values with exactly 20 digits may be valid uint64 (e.g. 18446744073709551615 = -1)
            // — use bcmath two's complement.  Values with 21+ digits exceed uint64 and should
            // be stored as float so that f32/f64.const can approximate them.
            $neg    = ($clean !== '' && $clean[0] === '-');
            $absStr = ltrim($clean, '+-');
            if (strlen($absStr) >= 20) {
                // Check if the value fits in uint64 (≤ 18446744073709551615 = 2^64-1)
                if (strlen($absStr) === 20
                    && bccomp($absStr, '18446744073709551615') <= 0
                ) {
                    // Compute hi/lo 32-bit halves via bcmath, then combine
                    $hi   = (int)bcdiv($absStr, '4294967296', 0);
                    $lo   = (int)bcmod($absStr, '4294967296');
                    $val  = ($hi << 32) | $lo;
                    if ($neg) $val = -$val;
                    return new Token(Token::INT, $val, $line);
                }
                // Beyond uint64 range: store as float for f32/f64.const contexts
                $fv = (float)$clean;
                return new Token(Token::FLOAT, $fv, $line, is_infinite($fv) ? $clean : null);
            }
            // For negative 19-digit values, check if absolute value > 2^63 (out of int64 range)
            if ($neg && strlen($absStr) === 19 && bccomp($absStr, '9223372036854775808') > 0) {
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
                // For hex floats with long fractional parts (>13 hex digits), the f64
                // intermediate may lose precision for f32 context.  Store the raw string
                // in the token so the parser can do a single-round f32 conversion.
                $rawStr = null;
                $dotP   = strpos($clean, '.');
                if ($dotP !== false) {
                    // strcspn($clean, 'pP', $dotP) returns the number of characters
                    // starting at $dotP that are not 'p'/'P'.  This length includes the
                    // '.' itself, so the fractional-digit count is $pP - 1.
                    // (Do NOT subtract $dotP again — $pP is already relative to $dotP.)
                    $pP      = strcspn($clean, 'pP', $dotP);
                    $fracLen = $pP - 1;
                    if ($fracLen > 13) {
                        $rawStr = $clean;
                    }
                }
                $parsedVal = self::parseHexFloat($clean);
                // Store rawString for all non-trivial hex floats, especially those that
                // overflow to INF so the parser can distinguish from literal inf.
                if ($rawStr === null && is_infinite($parsedVal)) {
                    $rawStr = $clean;
                }
                return new Token(Token::FLOAT, $parsedVal, $line, $rawStr);
            }
            // For decimal floats with many significant digits (>17), store
            // the raw string so the parser can do exact decimal→f32 conversion.
            $decRaw = null;
            if (preg_match('/^[+-]?(\d+)(?:\.(\d+))?(?:[eE][+-]?\d+)?$/', $clean, $dm)) {
                $sigDigits = strlen(ltrim(($dm[1] ?? '') . ($dm[2] ?? ''), '0'));
                if ($sigDigits > 17) {
                    $decRaw = $clean;
                }
            }
            $decVal = (float)$clean;
            // Store rawString for decimal floats that overflow to INF
            if ($decRaw === null && is_infinite($decVal)) {
                $decRaw = $clean;
            }
            return new Token(Token::FLOAT, $decVal, $line, $decRaw);
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
        // E < 0: M / 2^(-E) = M * 5^scale / 10^scale (exact representation).
        // Using the exact decimal avoids the truncation error of the old approach,
        // which caused incorrect round-to-even for exact midpoint values.
        $scale = -$E;
        $M5    = bcmul($M, bcpow('5', (string)$scale));
        $q     = str_pad($M5, $scale + 1, '0', STR_PAD_LEFT);
        $ip    = substr($q, 0, strlen($q) - $scale) ?: '0';
        $fp    = substr($q, -$scale);
        return (float)("$ip.$fp");
    }

    /**
     * Parse a hex float string and produce the correctly-rounded f32 value,
     * avoiding double-rounding through f64.
     * Throws WasmError if the value is out of f32 range (would produce ±INF).
     */
    public static function parseHexFloatAsF32(string $s): float
    {
        $sign = 1;
        if ($s !== '' && $s[0] === '-') { $sign = -1; $s = substr($s, 1); }
        elseif ($s !== '' && $s[0] === '+') { $s = substr($s, 1); }
        $s = substr($s, 2); // strip 0x

        $parts       = preg_split('/[pP]/', $s, 2);
        $mantissaStr = $parts[0];
        $bexp        = isset($parts[1]) ? (int)$parts[1] : 0;

        $dotPos  = strpos($mantissaStr, '.');
        $intHex  = $dotPos === false ? $mantissaStr : substr($mantissaStr, 0, $dotPos);
        $fracHex = $dotPos === false ? '' : substr($mantissaStr, $dotPos + 1);
        $fracLen = strlen($fracHex);

        // Build exact integer M (all hex digits concatenated) and exponent E.
        $hexStr = ($intHex !== '' ? $intHex : '0') . $fracHex;
        $E      = $bexp - 4 * $fracLen;
        $M      = '0';
        for ($i = 0, $n = strlen($hexStr); $i < $n; $i++) {
            $M = bcadd(bcmul($M, '16'), (string)hexdec($hexStr[$i]));
        }
        if ($M === '0') {
            return 0.0;
        }

        // Find k = position of the most-significant bit of M (0-indexed).
        // For a hex integer we can do this from the leading hex digit.
        $hexM  = strtolower(ltrim(self::decToHex($M), '0'));
        if ($hexM === '') $hexM = '0';
        $k     = 4 * (strlen($hexM) - 1) + (strlen(decbin(hexdec($hexM[0]))) - 1);

        // Normalised f32 exponent (unbiased).
        $expUnbiased = $k + $E;

        // f32 INF threshold: exact value = (2^25 - 1) * 2^103
        // Values >= threshold round to INF in f32.
        $threshM = bcpow('2', '25');
        $threshM = bcsub($threshM, '1'); // 2^25 - 1 = 33554431
        // Compare M * 2^E >= threshM * 2^103  ↔  M * 2^(E-103) >= threshM
        $cmpExp = $E - 103;
        if ($cmpExp >= 0) {
            // M * 2^cmpExp vs threshM
            $lhs = bcmul($M, bcpow('2', (string)$cmpExp));
            $cmp = bccomp($lhs, $threshM);
        } else {
            // M vs threshM * 2^(-cmpExp)
            $rhs = bcmul($threshM, bcpow('2', (string)(-$cmpExp)));
            $cmp = bccomp($M, $rhs);
        }
        if ($cmp >= 0) {
            // Value is >= f32 INF threshold → out of range
            throw new \WasmRuntime\WasmError('constant out of range');
        }

        // Value is < INF threshold; it rounds to f32_max or a normal f32 value.
        // For the common "rounds to f32_max" case, return f32_max directly.
        if ($expUnbiased > 127) {
            // Must be in [f32_max, INF-threshold): rounds to f32_max.
            $f32max = (float)unpack('f', pack('V', 0x7F7FFFFF))[1];
            return $sign > 0 ? $f32max : -$f32max;
        }

        // Normal vs subnormal: choose a single shift applied directly to M.
        //   Normal  (expUnbiased >= -126): extract 24 bits (implicit 1 + 23 mantissa),
        //                                  shift = k - 23.
        //   Subnormal (expUnbiased < -126): stored_mantissa = M * 2^(E+149),
        //                                   shift = -(E+149).
        // Using a single shift avoids the two-stage approach that loses guard/sticky bits
        // in the subnormal case.
        if ($expUnbiased >= -126) {
            $shift = $k - 23;
            $isSub = false;
        } else {
            $shift = -($E + 149);
            $isSub = true;
        }

        if ($shift > 0) {
            $div    = bcpow('2', (string)$shift);
            $quot   = (int)bcdiv($M, $div, 0);
            $rem    = bcsub($M, bcmul((string)$quot, $div));
            $half   = bcdiv($div, '2', 0);
            $guard  = bccomp($rem, $half) >= 0 ? 1 : 0;
            if ($guard) {
                $sticky = bccomp(bcsub($rem, $half), '0') > 0 ? 1 : 0;
            } else {
                $sticky = bccomp($rem, '0') > 0 ? 1 : 0;
            }
        } elseif ($shift === 0) {
            $quot   = (int)$M;
            $guard  = 0;
            $sticky = 0;
        } else {
            // shift < 0: M has fewer bits than needed; left-shift.
            $quot   = (int)bcmul($M, bcpow('2', (string)(-$shift)));
            $guard  = 0;
            $sticky = 0;
        }

        // Round-to-nearest-even.
        if ($guard && ($sticky || ($quot & 1))) {
            $quot++;
        }

        // Build f32 bit pattern.
        if (!$isSub) {
            // Normal: quot is the 24-bit mantissa with implicit leading 1.
            if ($quot >= (1 << 24)) {
                // Carry propagated into the exponent.
                $quot >>= 1;
                $expUnbiased++;
            }
            if ($expUnbiased >= 128) {
                // Safety guard – should not happen given the threshold check above.
                throw new \WasmRuntime\WasmError('constant out of range');
            }
            $biasedExp = $expUnbiased + 127;
            $mantBits  = $quot & 0x7FFFFF;
            $bits32    = ($sign < 0 ? 0x80000000 : 0) | ($biasedExp << 23) | $mantBits;
        } else {
            // Subnormal: quot is the 23-bit stored mantissa (no implicit leading 1).
            if ($quot >= (1 << 23)) {
                // Rounded up to the smallest normal f32 (biased exp = 1, mantissa = 0).
                $bits32 = ($sign < 0 ? 0x80000000 : 0) | (1 << 23);
            } else {
                $bits32 = ($sign < 0 ? 0x80000000 : 0) | ($quot & 0x7FFFFF);
            }
        }
        return (float)unpack('f', pack('V', (int)$bits32))[1];
    }

    /**
     * Exact single-round decimal-to-f32 conversion using BCMath.
     *
     * Avoids double-rounding through f64 intermediate for decimal strings
     * with more significant digits than f64 can represent exactly (~17).
     *
     * Algorithm:
     *  1. Parse decimal string to exact M * 10^E (integers).
     *  2. Get f32 candidate from f64 approximation.
     *  3. Compare exact decimal value with the midpoint between the f32
     *     candidate and its neighbor to determine correct rounding.
     */
    public static function parseDecFloatAsF32(string $s): float
    {
        $sign = 1;
        $str  = $s;
        if ($str !== '' && $str[0] === '-') { $sign = -1; $str = substr($str, 1); }
        elseif ($str !== '' && $str[0] === '+') { $str = substr($str, 1); }

        // Get f64 approximation
        $f64 = (float)$str;
        if ($f64 === 0.0 || is_infinite($f64) || is_nan($f64)) {
            $r = (float)unpack('f', pack('f', $f64))[1];
            return $sign < 0 ? -$r : $r;
        }

        // Parse decimal to exact M * 10^E
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $str, $m)) {
            $intPart  = $m[1];
            $fracPart = $m[2] ?? '';
            $exp10    = (int)($m[3] ?? '0');
            $M        = ltrim($intPart . $fracPart, '0') ?: '0';
            $E10      = $exp10 - strlen($fracPart);
        } else {
            $r = (float)unpack('f', pack('f', (float)$s))[1];
            return $r;
        }

        // Get f32 candidate from f64
        $absBits = unpack('V', pack('f', $f64))[1];
        if (($absBits & 0x7FFFFFFF) >= 0x7F800000) {
            // INF or NaN — keep as-is
            $r = (float)unpack('f', pack('V', $absBits))[1];
            return $sign < 0 ? -$r : $r;
        }

        // Compare exact decimal with the f32 candidate
        [$candMant, $candShift] = self::f32ExactComponents($absBits);
        $cmp = self::bcCompareDec10Bin2($M, $E10, (string)$candMant, $candShift);

        if ($cmp === 0) {
            // Exact match
            $r = (float)unpack('f', pack('V', $absBits))[1];
            return $sign < 0 ? -$r : $r;
        }

        // Determine neighbor
        $neighborBits = $cmp > 0 ? $absBits + 1 : ($absBits > 0 ? $absBits - 1 : 0);

        // Compute midpoint between candidate and neighbor
        [$nMant, $nShift] = self::f32ExactComponents($neighborBits);

        // Midpoint = (candMant * 2^candShift + nMant * 2^nShift) / 2
        // = (candMant * 2^(candShift - minShift) + nMant * 2^(nShift - minShift)) * 2^(minShift - 1)
        $minShift = min($candShift, $nShift);
        $aMant    = bcmul((string)$candMant, bcpow('2', (string)($candShift - $minShift)));
        $bMant    = bcmul((string)$nMant, bcpow('2', (string)($nShift - $minShift)));
        $midMant  = bcadd($aMant, $bMant); // = midpoint_value / 2^(minShift - 1)
        $midShift = $minShift; // midpoint = midMant * 2^midShift / 2
        // Compare: decimal vs midMant * 2^midShift / 2
        // ↔ 2 * decimal  vs  midMant * 2^midShift
        // ↔ 2*M * 10^E10  vs  midMant * 2^midShift
        $cmpMid = self::bcCompareDec10Bin2(bcmul($M, '2'), $E10, $midMant, $midShift);

        if ($cmpMid === 0) {
            // Exact midpoint — round to even (prefer the one with LSB = 0)
            $useBits = ($absBits & 1) === 0 ? $absBits : $neighborBits;
        } elseif (($cmpMid > 0) === ($cmp > 0)) {
            // Decimal is on the far side of midpoint → use neighbor
            $useBits = $neighborBits;
        } else {
            // Decimal is on the near side of midpoint → keep candidate
            $useBits = $absBits;
        }

        $r = (float)unpack('f', pack('V', $useBits))[1];
        return $sign < 0 ? -$r : $r;
    }

    /**
     * Return the exact integer mantissa and binary exponent for an f32 bit pattern.
     * f32_value = mantissa * 2^shift  (unsigned).
     * @return array{int, int}
     */
    private static function f32ExactComponents(int $bits): array
    {
        $bexp = ($bits >> 23) & 0xFF;
        $mant = $bits & 0x7FFFFF;
        if ($bexp === 0) {
            return [$mant, -149]; // subnormal: mant * 2^-149
        }
        return [0x800000 | $mant, $bexp - 150]; // normal: (0x800000|mant) * 2^(bexp-150)
    }

    /**
     * Compare a * 10^aE10 with b * 2^bE2 using BCMath.
     * Returns -1, 0, or 1.
     */
    private static function bcCompareDec10Bin2(string $a, int $aE10, string $b, int $bE2): int
    {
        // a * 10^aE10 = a * 2^aE10 * 5^aE10
        // b * 2^bE2
        // Multiply both sides so all exponents become non-negative.
        $shift2 = max(0, -$aE10, -$bE2);
        $shift5 = max(0, -$aE10);

        $l2 = $aE10 + $shift2;
        $l5 = $aE10 + $shift5;
        $r2 = $bE2  + $shift2;
        $r5 = $shift5; // 0 + shift5

        $lhs = $a;
        if ($l2 > 0) $lhs = bcmul($lhs, bcpow('2', (string)$l2));
        if ($l5 > 0) $lhs = bcmul($lhs, bcpow('5', (string)$l5));

        $rhs = $b;
        if ($r2 > 0) $rhs = bcmul($rhs, bcpow('2', (string)$r2));
        if ($r5 > 0) $rhs = bcmul($rhs, bcpow('5', (string)$r5));

        return bccomp($lhs, $rhs);
    }

    /** Convert a BCMath decimal integer string to lowercase hexadecimal. */
    private static function decToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $rem = (int)bcmod($dec, '16');
            $hex = dechex($rem) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return $hex !== '' ? $hex : '0';
    }

    private function isSymStart(string $ch): bool
    {
        // Any printable ASCII except whitespace and reserved chars: '(', ')', '"', ';'
        // Also accept multi-byte UTF-8 bytes (>= 0x80) for Unicode identifiers
        $o = ord($ch);
        if ($o >= 0x80) return true; // UTF-8 continuation/lead byte
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }

    private function isSymChar(string $ch): bool
    {
        $o = ord($ch);
        if ($o >= 0x80) return true;
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }

    private function isIdChar(string $ch): bool
    {
        $o = ord($ch);
        if ($o >= 0x80) return true;
        return $o >= 0x21 && $o <= 0x7E && !in_array($ch, ['(', ')', '"', ';'], true);
    }
}
