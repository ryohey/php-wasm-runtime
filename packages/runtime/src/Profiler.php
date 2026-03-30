<?php

declare(strict_types=1);

namespace WasmRuntime;

/**
 * Lightweight runtime profiler for php-wasm-runtime.
 *
 * Enable with:
 *   WASM_RUNTIME_PROFILE=1
 *
 * Optional:
 *   WASM_RUNTIME_PROFILE_OPCODES=1   # per-opcode timing/count
 *   WASM_RUNTIME_PROFILE_OUT=stderr  # stderr|stdout|/path/to/file
 */
final class Profiler
{
    private static bool $initialized = false;
    private static bool $enabled = false;
    private static bool $opcodeEnabled = false;
    private static bool $dumped = false;

    /** @var array<int,array{name:string,startNs:int}> */
    private static array $stack = [];

    /** @var array<string,array{count:int,totalNs:int,maxNs:int}> */
    private static array $sections = [];

    /** @var array<string,array{count:int,totalNs:int,maxNs:int}> */
    private static array $opcodes = [];

    /** @var array<string,mixed> */
    private static array $meta = [];

    public static function enabled(): bool
    {
        self::init();
        return self::$enabled;
    }

    public static function opcodeEnabled(): bool
    {
        self::init();
        return self::$enabled && self::$opcodeEnabled;
    }

    public static function enter(string $name): void
    {
        if (!self::enabled()) {
            return;
        }
        self::$stack[] = ['name' => $name, 'startNs' => hrtime(true)];
    }

    public static function leave(string $name): void
    {
        if (!self::enabled()) {
            return;
        }

        $frame = array_pop(self::$stack);
        if ($frame === null) {
            return;
        }

        $elapsedNs = hrtime(true) - $frame['startNs'];
        $sectionName = $frame['name'] === $name ? $name : $frame['name'] . '(!=' . $name . ')';
        self::addSample(self::$sections, $sectionName, $elapsedNs);
    }

    public static function addSectionDuration(string $name, int $elapsedNs): void
    {
        if (!self::enabled()) {
            return;
        }
        self::addSample(self::$sections, $name, $elapsedNs);
    }

    public static function addOpcodeSample(string $opcode, int $elapsedNs): void
    {
        if (!self::opcodeEnabled()) {
            return;
        }
        self::addSample(self::$opcodes, $opcode, $elapsedNs);
    }

    public static function setMeta(string $key, mixed $value): void
    {
        if (!self::enabled()) {
            return;
        }
        self::$meta[$key] = $value;
    }

    public static function dump(): void
    {
        self::init();
        if (!self::$enabled || self::$dumped) {
            return;
        }
        self::$dumped = true;

        $sections = self::toSortedRows(self::$sections);
        $opcodes  = self::$opcodeEnabled ? self::toSortedRows(self::$opcodes) : [];

        $payload = [
            'generatedAt' => gmdate('c'),
            'meta' => self::$meta,
            'sections' => $sections,
            'opcodes' => $opcodes,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $out = getenv('WASM_RUNTIME_PROFILE_OUT') ?: 'stderr';
        $text = "[wasm-runtime-profiler]\n" . $json . "\n";

        if ($out === 'stdout') {
            fwrite(STDOUT, $text);
            return;
        }

        if ($out === 'stderr' || $out === '-') {
            fwrite(STDERR, $text);
            return;
        }

        @file_put_contents($out, $text);
    }

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $flag = getenv('WASM_RUNTIME_PROFILE');
        self::$enabled = in_array(strtolower((string)$flag), ['1', 'true', 'yes', 'on'], true);
        if (!self::$enabled) {
            return;
        }

        $opcodeFlag = getenv('WASM_RUNTIME_PROFILE_OPCODES');
        self::$opcodeEnabled = in_array(strtolower((string)$opcodeFlag), ['1', 'true', 'yes', 'on'], true);

        self::$meta['pid'] = getmypid();
        self::$meta['opcodeProfiling'] = self::$opcodeEnabled;

        register_shutdown_function([self::class, 'dump']);
    }

    /** @param array<string,array{count:int,totalNs:int,maxNs:int}> $bucket */
    private static function addSample(array &$bucket, string $name, int $elapsedNs): void
    {
        $row = $bucket[$name] ?? ['count' => 0, 'totalNs' => 0, 'maxNs' => 0];
        $row['count']++;
        $row['totalNs'] += max(0, $elapsedNs);
        $row['maxNs'] = max($row['maxNs'], $elapsedNs);
        $bucket[$name] = $row;
    }

    /**
     * @param  array<string,array{count:int,totalNs:int,maxNs:int}> $bucket
     * @return array<int,array{name:string,count:int,totalMs:float,avgMs:float,maxMs:float}>
     */
    private static function toSortedRows(array $bucket): array
    {
        uasort($bucket, static fn(array $a, array $b): int => $b['totalNs'] <=> $a['totalNs']);
        $rows = [];
        foreach ($bucket as $name => $row) {
            $count = max(1, $row['count']);
            $rows[] = [
                'name' => $name,
                'count' => $row['count'],
                'totalMs' => round($row['totalNs'] / 1_000_000, 3),
                'avgMs' => round(($row['totalNs'] / $count) / 1_000_000, 3),
                'maxMs' => round($row['maxNs'] / 1_000_000, 3),
            ];
        }
        return $rows;
    }
}
