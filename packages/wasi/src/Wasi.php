<?php

declare(strict_types=1);

namespace WasmRuntime\Wasi;

use WasmRuntime\Instance;
use WasmRuntime\Memory;
use WasmRuntime\RawHostFunc;
use WasmRuntime\WasmValue;

/**
 * WASI (WebAssembly System Interface) – wasi_snapshot_preview1 implementation.
 *
 * Usage:
 *   $wasi     = new Wasi(args: $argv, env: $_SERVER, preopenDirs: ['.']);
 *   $imports  = $wasi->getImports();
 *   $instance = Instance::instantiate($module, $imports);
 *   $wasi->bindInstance($instance);
 *   try {
 *       $instance->callExport('_start', []);
 *   } catch (WasiExitException $e) {
 *       exit($e->exitCode);
 *   }
 */
final class Wasi
{
    private ?Instance $instance = null;

    /**
     * @var array<int, resource|null> Open file descriptors (fd >= 3 are user-opened)
     *   resource = open file handle, null = was closed
     */
    private array $openFds = [];

    /** @var array<int, string> Pre-opened directory fds => path */
    private array $preopens = [];

    /** @var array<int, int> FD flags per fd (FDFLAGS_APPEND etc.) */
    private array $fdFlags = [];

    /** @var array<int, int> Filetype per fd */
    private array $fdTypes = [];

    /** @var array<int, int> Base rights per fd */
    private array $fdRightsBase = [];

    /** @var array<int, int> Inheriting rights per fd */
    private array $fdRightsInheriting = [];

    private int $nextFd = 3; // 0=stdin, 1=stdout, 2=stderr are fixed

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    /** @var resource */
    private $stdin;

    // ---- WASI constants ----
    // Filetypes
    private const FILETYPE_UNKNOWN          = 0;
    private const FILETYPE_BLOCK_DEVICE     = 1;
    private const FILETYPE_CHARACTER_DEVICE = 2;
    private const FILETYPE_DIRECTORY        = 3;
    private const FILETYPE_REGULAR_FILE     = 4;
    private const FILETYPE_SYMBOLIC_LINK    = 7;

    // Fdflags
    private const FDFLAGS_APPEND   = 0x01;
    private const FDFLAGS_DSYNC    = 0x02;
    private const FDFLAGS_NONBLOCK = 0x04;
    private const FDFLAGS_RSYNC    = 0x08;
    private const FDFLAGS_SYNC     = 0x10;

    // Oflags
    private const OFLAGS_CREAT     = 0x01;
    private const OFLAGS_DIRECTORY = 0x02;
    private const OFLAGS_EXCL      = 0x04;
    private const OFLAGS_TRUNC     = 0x08;

    // Lookupflags
    private const LOOKUPFLAGS_SYMLINK_FOLLOW = 0x01;

    // Fstflags
    private const FSTFLAGS_ATIM     = 0x01;
    private const FSTFLAGS_ATIM_NOW = 0x02;
    private const FSTFLAGS_MTIM     = 0x04;
    private const FSTFLAGS_MTIM_NOW = 0x08;

    // Whence
    private const WHENCE_SET = 0;
    private const WHENCE_CUR = 1;
    private const WHENCE_END = 2;

    // Rights (bitmask)
    private const RIGHT_FD_DATASYNC             = 1 << 0;
    private const RIGHT_FD_READ                 = 1 << 1;
    private const RIGHT_FD_SEEK                 = 1 << 2;
    private const RIGHT_FD_FDSTAT_SET_FLAGS     = 1 << 3;
    private const RIGHT_FD_SYNC                 = 1 << 4;
    private const RIGHT_FD_TELL                 = 1 << 5;
    private const RIGHT_FD_WRITE                = 1 << 6;
    private const RIGHT_FD_ADVISE               = 1 << 7;
    private const RIGHT_FD_ALLOCATE             = 1 << 8;
    private const RIGHT_PATH_CREATE_DIRECTORY   = 1 << 9;
    private const RIGHT_PATH_CREATE_FILE        = 1 << 10;
    private const RIGHT_PATH_LINK_SOURCE        = 1 << 11;
    private const RIGHT_PATH_LINK_TARGET        = 1 << 12;
    private const RIGHT_PATH_OPEN               = 1 << 13;
    private const RIGHT_FD_READDIR              = 1 << 14;
    private const RIGHT_PATH_READLINK           = 1 << 15;
    private const RIGHT_PATH_RENAME_SOURCE      = 1 << 16;
    private const RIGHT_PATH_RENAME_TARGET      = 1 << 17;
    private const RIGHT_PATH_FILESTAT_GET       = 1 << 18;
    private const RIGHT_PATH_FILESTAT_SET_SIZE  = 1 << 19;
    private const RIGHT_PATH_FILESTAT_SET_TIMES = 1 << 20;
    private const RIGHT_FD_FILESTAT_GET         = 1 << 21;
    private const RIGHT_FD_FILESTAT_SET_SIZE    = 1 << 22;
    private const RIGHT_FD_FILESTAT_SET_TIMES   = 1 << 23;
    private const RIGHT_PATH_SYMLINK            = 1 << 24;
    private const RIGHT_PATH_REMOVE_DIRECTORY   = 1 << 25;
    private const RIGHT_PATH_UNLINK_FILE        = 1 << 26;
    private const RIGHT_POLL_FD_READWRITE       = 1 << 27;
    private const RIGHT_SOCK_SHUTDOWN           = 1 << 28;
    private const RIGHT_SOCK_ACCEPT             = 1 << 29;

    // All rights
    private const RIGHTS_ALL = (1 << 30) - 1;

    // Directory rights (base)
    private const RIGHTS_DIR_BASE =
        self::RIGHT_FD_FDSTAT_SET_FLAGS |
        self::RIGHT_FD_SYNC |
        self::RIGHT_FD_ADVISE |
        self::RIGHT_PATH_CREATE_DIRECTORY |
        self::RIGHT_PATH_CREATE_FILE |
        self::RIGHT_PATH_LINK_SOURCE |
        self::RIGHT_PATH_LINK_TARGET |
        self::RIGHT_PATH_OPEN |
        self::RIGHT_FD_READDIR |
        self::RIGHT_PATH_READLINK |
        self::RIGHT_PATH_RENAME_SOURCE |
        self::RIGHT_PATH_RENAME_TARGET |
        self::RIGHT_PATH_FILESTAT_GET |
        self::RIGHT_PATH_FILESTAT_SET_SIZE |
        self::RIGHT_PATH_FILESTAT_SET_TIMES |
        self::RIGHT_FD_FILESTAT_GET |
        self::RIGHT_FD_FILESTAT_SET_TIMES |
        self::RIGHT_PATH_SYMLINK |
        self::RIGHT_PATH_REMOVE_DIRECTORY |
        self::RIGHT_PATH_UNLINK_FILE;

    // File rights (base)
    private const RIGHTS_FILE_BASE =
        self::RIGHT_FD_DATASYNC |
        self::RIGHT_FD_READ |
        self::RIGHT_FD_SEEK |
        self::RIGHT_FD_FDSTAT_SET_FLAGS |
        self::RIGHT_FD_SYNC |
        self::RIGHT_FD_TELL |
        self::RIGHT_FD_WRITE |
        self::RIGHT_FD_ADVISE |
        self::RIGHT_FD_ALLOCATE |
        self::RIGHT_FD_FILESTAT_GET |
        self::RIGHT_FD_FILESTAT_SET_SIZE |
        self::RIGHT_FD_FILESTAT_SET_TIMES |
        self::RIGHT_POLL_FD_READWRITE;

    /**
     * @param string[]             $args        argv (index 0 is the program name)
     * @param array<string,string> $env         environment variables (key => value)
     * @param string[]             $preopenDirs directories to pre-open for path access
     * @param resource|null        $stdout      stream for fd 1 (default: STDOUT)
     * @param resource|null        $stderr      stream for fd 2 (default: STDERR)
     * @param resource|null        $stdin       stream for fd 0 (default: STDIN)
     */
    public function __construct(
        private readonly array $args = [],
        private readonly array $env = [],
        array $preopenDirs = [],
        $stdout = null,
        $stderr = null,
        $stdin  = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->stdin  = $stdin  ?? STDIN;

        // Initialize stdio rights
        $this->fdTypes[0] = self::FILETYPE_CHARACTER_DEVICE;
        $this->fdTypes[1] = self::FILETYPE_CHARACTER_DEVICE;
        $this->fdTypes[2] = self::FILETYPE_CHARACTER_DEVICE;
        $this->fdRightsBase[0] = self::RIGHTS_ALL;
        $this->fdRightsBase[1] = self::RIGHTS_ALL;
        $this->fdRightsBase[2] = self::RIGHTS_ALL;
        $this->fdRightsInheriting[0] = self::RIGHTS_ALL;
        $this->fdRightsInheriting[1] = self::RIGHTS_ALL;
        $this->fdRightsInheriting[2] = self::RIGHTS_ALL;

        foreach ($preopenDirs as $dir) {
            $fd = $this->nextFd++;
            $this->preopens[$fd] = $dir;
            $this->fdTypes[$fd] = self::FILETYPE_DIRECTORY;
            $this->fdRightsBase[$fd] = self::RIGHTS_DIR_BASE;
            $this->fdRightsInheriting[$fd] = self::RIGHTS_DIR_BASE | self::RIGHTS_FILE_BASE;
        }
    }

    /**
     * Bind the instantiated module so WASI functions can access its memory.
     * Must be called before invoking any exported function.
     */
    public function bindInstance(Instance $instance): void
    {
        $this->instance = $instance;
    }

    /**
     * Returns the imports map for Instance::instantiate().
     *
     * @return array<string, array<string, callable>>
     */
    public function getImports(): array
    {
        return [
            'wasi_snapshot_preview1' => [
                'args_get'              => new RawHostFunc($this->argsGetRaw(...)),
                'args_sizes_get'        => new RawHostFunc($this->argsSizesGetRaw(...)),
                'environ_get'           => new RawHostFunc($this->environGetRaw(...)),
                'environ_sizes_get'     => new RawHostFunc($this->environSizesGetRaw(...)),
                'clock_time_get'        => new RawHostFunc($this->clockTimeGetRaw(...)),
                'clock_res_get'         => new RawHostFunc($this->clockResGetRaw(...)),
                'fd_advise'             => fn(array $a) => $this->fdAdvise($a),
                'fd_allocate'           => fn(array $a) => $this->fdAllocate($a),
                'fd_close'              => new RawHostFunc($this->fdCloseRaw(...)),
                'fd_datasync'           => fn(array $a) => $this->fdDatasync($a),
                'fd_fdstat_get'         => new RawHostFunc($this->fdFdstatGetRaw(...)),
                'fd_fdstat_set_flags'   => fn(array $a) => $this->fdFdstatSetFlags($a),
                'fd_fdstat_set_rights'  => fn(array $a) => $this->fdFdstatSetRights($a),
                'fd_filestat_get'       => new RawHostFunc($this->fdFilestatGetRaw(...)),
                'fd_filestat_set_size'  => fn(array $a) => $this->fdFilestatSetSize($a),
                'fd_filestat_set_times' => fn(array $a) => $this->fdFilestatSetTimes($a),
                'fd_pread'              => new RawHostFunc($this->fdPreadRaw(...)),
                'fd_prestat_get'        => new RawHostFunc($this->fdPrestatGetRaw(...)),
                'fd_prestat_dir_name'   => new RawHostFunc($this->fdPrestatDirNameRaw(...)),
                'fd_pwrite'             => new RawHostFunc($this->fdPwriteRaw(...)),
                'fd_read'               => new RawHostFunc($this->fdReadRaw(...)),
                'fd_readdir'            => fn(array $a) => $this->fdReaddir($a),
                'fd_renumber'           => fn(array $a) => $this->fdRenumber($a),
                'fd_seek'               => new RawHostFunc($this->fdSeekRaw(...)),
                'fd_sync'               => fn(array $a) => $this->fdSync($a),
                'fd_tell'               => new RawHostFunc($this->fdTellRaw(...)),
                'fd_write'              => new RawHostFunc($this->fdWriteRaw(...)),
                'path_create_directory' => fn(array $a) => $this->pathCreateDirectory($a),
                'path_filestat_get'     => fn(array $a) => $this->pathFilestatGet($a),
                'path_filestat_set_times' => fn(array $a) => $this->pathFilestatSetTimes($a),
                'path_link'             => fn(array $a) => $this->pathLink($a),
                'path_open'             => new RawHostFunc($this->pathOpenRaw(...)),
                'path_readlink'         => fn(array $a) => $this->pathReadlink($a),
                'path_remove_directory' => fn(array $a) => $this->pathRemoveDirectory($a),
                'path_rename'           => fn(array $a) => $this->pathRename($a),
                'path_symlink'          => fn(array $a) => $this->pathSymlink($a),
                'path_unlink_file'      => fn(array $a) => $this->pathUnlinkFile($a),
                'poll_oneoff'           => fn(array $a) => $this->pollOneoff($a),
                'proc_exit'             => new RawHostFunc($this->procExitRaw(...)),
                'random_get'            => new RawHostFunc($this->randomGetRaw(...)),
                'sched_yield'           => fn(array $a) => $this->ok(),
                'sock_accept'           => fn(array $a) => $this->err(Errno::NOSYS),
                'sock_recv'             => fn(array $a) => $this->err(Errno::NOSYS),
                'sock_send'             => fn(array $a) => $this->err(Errno::NOSYS),
                'sock_shutdown'         => fn(array $a) => $this->err(Errno::NOSYS),
            ],
        ];
    }

    // ================================================================
    //  Private helpers
    // ================================================================

    private function mem(): Memory
    {
        return $this->instance->memories[0];
    }

    private function addr(WasmValue $v): int
    {
        return $v->value & 0xFFFFFFFF;
    }

    private static function rawAddr(array $args, int $base, int $index): int
    {
        return ((int)($args[$base + $index] ?? 0)) & 0xFFFFFFFF;
    }

    private function ok(): array
    {
        return [WasmValue::i32(Errno::SUCCESS)];
    }

    private function err(int $errno): array
    {
        return [WasmValue::i32($errno)];
    }

    private function okRaw(): int
    {
        return Errno::SUCCESS;
    }

    private function errRaw(int $errno): int
    {
        return $errno;
    }

    /** Check if fd is valid (std, preopen, or open file) */
    private function fdValid(int $fd): bool
    {
        // An fd is valid if it has type metadata (set on creation, cleared on close)
        return isset($this->fdTypes[$fd]);
    }

    /** Get the resource handle for an fd, or null */
    private function fdResource(int $fd): mixed
    {
        if ($fd <= 2) {
            if (!isset($this->fdTypes[$fd])) return null; // closed
            // Check if stdio was renumbered to a file
            if (isset($this->openFds[$fd])) return $this->openFds[$fd];
            return match ($fd) {
                0 => $this->stdin,
                1 => $this->stdout,
                2 => $this->stderr,
            };
        }
        return $this->openFds[$fd] ?? null;
    }

    /** Resolve a guest path relative to a preopened dirfd, with sandbox enforcement */
    private function resolvePath(int $dirfd, string $relPath): ?string
    {
        $dirPath = $this->preopens[$dirfd] ?? null;
        if ($dirPath === null) {
            return null;
        }
        return rtrim($dirPath, '/') . '/' . $relPath;
    }

    /** Check that a resolved path does not escape the sandbox */
    private function checkSandbox(int $dirfd, string $relPath): ?int
    {
        $dirPath = $this->preopens[$dirfd] ?? null;
        if ($dirPath === null) return Errno::BADF;

        // Reject absolute paths
        if (str_starts_with($relPath, '/')) return Errno::PERM;

        // Normalize path components and check for sandbox escape
        $dirReal = realpath($dirPath);
        if ($dirReal === false) $dirReal = $dirPath;

        $absPath = rtrim($dirPath, '/') . '/' . $relPath;
        // Resolve .. manually to check for escape
        $parts = explode('/', $relPath);
        $depth = 0;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                $depth--;
                if ($depth < 0) return Errno::PERM;
            } else {
                $depth++;
            }
        }

        return null; // OK
    }

    /** Detect filetype from a real filesystem path */
    private function filetypeFromPath(string $path, bool $followSymlinks = true): int
    {
        if (!$followSymlinks && is_link($path)) {
            return self::FILETYPE_SYMBOLIC_LINK;
        }
        if (is_dir($path)) {
            return self::FILETYPE_DIRECTORY;
        }
        if (is_file($path)) {
            return self::FILETYPE_REGULAR_FILE;
        }
        if (is_link($path)) {
            return self::FILETYPE_SYMBOLIC_LINK;
        }
        return self::FILETYPE_UNKNOWN;
    }

    /**
     * Get nanosecond-precision timestamps for a file path using Python.
     * Falls back to PHP stat() (second precision) if Python is unavailable.
     *
     * @param bool $followSymlinks If true, use os.stat (follows symlinks); if false, use os.lstat.
     * @return array{atime_ns: int, mtime_ns: int, ctime_ns: int}|null
     */
    private function statNs(string $path, bool $followSymlinks = false): ?array
    {
        $escaped = escapeshellarg($path);
        $func = $followSymlinks ? 'os.stat' : 'os.lstat';
        $cmd = "python3 -c " . escapeshellarg(
            "import os; s = {$func}({$escaped}); print(s.st_atime_ns, s.st_mtime_ns, s.st_ctime_ns)"
        );
        $output = @shell_exec($cmd);
        if ($output !== null && $output !== false) {
            $parts = explode(' ', trim($output));
            if (count($parts) === 3) {
                return [
                    'atime_ns' => (int)$parts[0],
                    'mtime_ns' => (int)$parts[1],
                    'ctime_ns' => (int)$parts[2],
                ];
            }
        }
        return null;
    }

    /**
     * Set nanosecond-precision atime/mtime on a file using Python's os.utime.
     * Falls back to PHP touch() (second precision) if Python is unavailable.
     *
     * @param string $path File path
     * @param int $atimeNs Access time in nanoseconds
     * @param int $mtimeNs Modification time in nanoseconds
     * @param bool $followSymlinks Whether to follow symlinks
     * @return bool Success
     */
    private function utimeNs(string $path, int $atimeNs, int $mtimeNs, bool $followSymlinks = true): bool
    {
        $escaped = escapeshellarg($path);
        $follow = $followSymlinks ? 'True' : 'False';
        $cmd = "python3 -c " . escapeshellarg(
            "import os; os.utime({$escaped}, ns=({$atimeNs}, {$mtimeNs}), follow_symlinks={$follow})"
        );
        $ret = null;
        @exec($cmd, $output, $ret);
        if ($ret === 0) {
            return true;
        }
        // Fall back to PHP touch (second precision)
        return @touch($path, (int)($mtimeNs / 1_000_000_000), (int)($atimeNs / 1_000_000_000));
    }

    /** Write a 64-byte filestat struct to memory */
    private function writeFilestat(int $ptr, array $stat, int $filetype, ?string $path = null, bool $followSymlinks = true): void
    {
        $mem = $this->mem();
        $mem->storeI64($ptr + 0, $stat['dev'] ?? 0);          // dev
        $mem->storeI64($ptr + 8, $stat['ino'] ?? 0);          // ino
        $mem->storeI8($ptr + 16, $filetype);                   // filetype
        // 7 bytes padding (17-23)
        for ($i = 17; $i < 24; $i++) $mem->storeI8($ptr + $i, 0);
        $mem->storeI64($ptr + 24, $stat['nlink'] ?? 1);       // nlink
        $mem->storeI64($ptr + 32, $stat['size'] ?? 0);        // size

        // Try nanosecond-precision timestamps via Python, fall back to PHP stat seconds
        $nsData = ($path !== null) ? $this->statNs($path, $followSymlinks) : null;
        if ($nsData !== null) {
            $atimNs = $nsData['atime_ns'];
            $mtimNs = $nsData['mtime_ns'];
            $ctimNs = $nsData['ctime_ns'];
        } else {
            $atimNs = (int)(($stat['atime'] ?? 0) * 1_000_000_000);
            $mtimNs = (int)(($stat['mtime'] ?? 0) * 1_000_000_000);
            $ctimNs = (int)(($stat['ctime'] ?? 0) * 1_000_000_000);
        }
        $mem->storeI64($ptr + 40, $atimNs);                   // atim
        $mem->storeI64($ptr + 48, $mtimNs);                   // mtim
        $mem->storeI64($ptr + 56, $ctimNs);                   // ctim
    }

    /** Check that fd has the given right */
    private function hasRight(int $fd, int $right): bool
    {
        $base = $this->fdRightsBase[$fd] ?? self::RIGHTS_ALL;
        return ($base & $right) !== 0;
    }

    // ================================================================
    //  WASI syscall implementations
    // ================================================================

    // ---- fd_write ----

    private function fdWrite(array $args): array
    {
        $fd          = $args[0]->value;
        $iovs        = $this->addr($args[1]);
        $iovsLen     = $args[2]->value;
        $nwrittenPtr = $this->addr($args[3]);

        if (!$this->fdValid($fd)) {
            return $this->err(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_WRITE)) {
            return $this->err(Errno::BADF);
        }

        $resource = $this->fdResource($fd);
        if ($resource === null && $fd > 2) {
            // Directory fd — cannot write
            if (isset($this->preopens[$fd])) {
                return $this->err(Errno::ISDIR);
            }
            return $this->err(Errno::BADF);
        }

        $mem          = $this->mem();
        $totalWritten = 0;

        for ($i = 0; $i < $iovsLen; $i++) {
            $base   = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($base);
            $bufLen = $mem->loadU32($base + 4);

            if ($bufLen === 0) {
                continue;
            }

            $data = substr($mem->rawBytes(), $bufPtr, $bufLen);
            $written = @fwrite($resource, $data);
            if ($written === false) {
                if ($totalWritten === 0) return $this->err(Errno::IO);
                break;
            }
            $totalWritten += $written;
        }

        $mem->storeI32($nwrittenPtr, $totalWritten);
        return $this->ok();
    }

    private function fdWriteRaw(array $args, int $base, int $count): int
    {
        $fd          = (int)($args[$base] ?? 0);
        $iovs        = self::rawAddr($args, $base, 1);
        $iovsLen     = (int)($args[$base + 2] ?? 0);
        $nwrittenPtr = self::rawAddr($args, $base, 3);

        if (!$this->fdValid($fd)) {
            return $this->errRaw(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_WRITE)) {
            return $this->errRaw(Errno::BADF);
        }

        $resource = $this->fdResource($fd);
        if ($resource === null && $fd > 2) {
            if (isset($this->preopens[$fd])) {
                return $this->errRaw(Errno::ISDIR);
            }
            return $this->errRaw(Errno::BADF);
        }

        $mem          = $this->mem();
        $totalWritten = 0;

        for ($i = 0; $i < $iovsLen; $i++) {
            $iovBase = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($iovBase);
            $bufLen = $mem->loadU32($iovBase + 4);

            if ($bufLen === 0) {
                continue;
            }

            $data = substr($mem->rawBytes(), $bufPtr, $bufLen);
            $written = @fwrite($resource, $data);
            if ($written === false) {
                if ($totalWritten === 0) {
                    return $this->errRaw(Errno::IO);
                }
                break;
            }
            $totalWritten += $written;
        }

        $mem->storeI32($nwrittenPtr, $totalWritten);
        return $this->okRaw();
    }

    // ---- fd_read ----

    private function fdRead(array $args): array
    {
        $fd       = $args[0]->value;
        $iovs     = $this->addr($args[1]);
        $iovsLen  = $args[2]->value;
        $nreadPtr = $this->addr($args[3]);

        if (!$this->fdValid($fd)) {
            return $this->err(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_READ)) {
            return $this->err(Errno::BADF);
        }
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }

        $resource = $this->fdResource($fd);
        if ($resource === null) {
            return $this->err(Errno::BADF);
        }

        $mem       = $this->mem();
        $totalRead = 0;

        for ($i = 0; $i < $iovsLen; $i++) {
            $base   = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($base);
            $bufLen = $mem->loadU32($base + 4);

            if ($bufLen === 0) {
                continue;
            }

            $data = @fread($resource, $bufLen);
            if ($data === false || $data === '') {
                break;
            }

            $readLen = strlen($data);
            $mem->init($bufPtr, $data);
            $totalRead += $readLen;

            if ($readLen < $bufLen) {
                break;
            }
        }

        $mem->storeI32($nreadPtr, $totalRead);
        return $this->ok();
    }

    private function fdReadRaw(array $args, int $base, int $count): int
    {
        $fd       = (int)($args[$base] ?? 0);
        $iovs     = self::rawAddr($args, $base, 1);
        $iovsLen  = (int)($args[$base + 2] ?? 0);
        $nreadPtr = self::rawAddr($args, $base, 3);

        if (!$this->fdValid($fd)) {
            return $this->errRaw(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_READ)) {
            return $this->errRaw(Errno::BADF);
        }
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::ISDIR);
        }

        $resource = $this->fdResource($fd);
        if ($resource === null) {
            return $this->errRaw(Errno::BADF);
        }

        $mem       = $this->mem();
        $totalRead = 0;

        for ($i = 0; $i < $iovsLen; $i++) {
            $iovBase = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($iovBase);
            $bufLen = $mem->loadU32($iovBase + 4);

            if ($bufLen === 0) {
                continue;
            }

            $data = @fread($resource, $bufLen);
            if ($data === false || $data === '') {
                break;
            }

            $readLen = strlen($data);
            $mem->init($bufPtr, $data);
            $totalRead += $readLen;

            if ($readLen < $bufLen) {
                break;
            }
        }

        $mem->storeI32($nreadPtr, $totalRead);
        return $this->okRaw();
    }

    // ---- fd_pread ----

    private function fdPread(array $args): array
    {
        $fd       = $args[0]->value;
        $iovs     = $this->addr($args[1]);
        $iovsLen  = $args[2]->value;
        $offset   = $args[3]->value; // i64
        $nreadPtr = $this->addr($args[4]);

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->err(Errno::BADF);

        $mem       = $this->mem();
        $totalRead = 0;

        // Save current position
        $savedPos = ftell($resource);
        fseek($resource, $offset, SEEK_SET);

        for ($i = 0; $i < $iovsLen; $i++) {
            $base   = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($base);
            $bufLen = $mem->loadU32($base + 4);

            if ($bufLen === 0) continue;

            $data = @fread($resource, $bufLen);
            if ($data === false || $data === '') break;

            $readLen = strlen($data);
            $mem->init($bufPtr, $data);
            $totalRead += $readLen;

            if ($readLen < $bufLen) break;
        }

        // Restore original position
        fseek($resource, $savedPos, SEEK_SET);

        $mem->storeI32($nreadPtr, $totalRead);
        return $this->ok();
    }

    private function fdPreadRaw(array $args, int $base, int $count): int
    {
        $fd       = (int)($args[$base] ?? 0);
        $iovs     = self::rawAddr($args, $base, 1);
        $iovsLen  = (int)($args[$base + 2] ?? 0);
        $offset   = (int)($args[$base + 3] ?? 0);
        $nreadPtr = self::rawAddr($args, $base, 4);

        if (!$this->fdValid($fd)) return $this->errRaw(Errno::BADF);
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::ISDIR);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->errRaw(Errno::BADF);

        $mem       = $this->mem();
        $totalRead = 0;
        $savedPos = ftell($resource);
        fseek($resource, $offset, SEEK_SET);

        for ($i = 0; $i < $iovsLen; $i++) {
            $iovBase = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($iovBase);
            $bufLen = $mem->loadU32($iovBase + 4);

            if ($bufLen === 0) continue;

            $data = @fread($resource, $bufLen);
            if ($data === false || $data === '') break;

            $readLen = strlen($data);
            $mem->init($bufPtr, $data);
            $totalRead += $readLen;

            if ($readLen < $bufLen) break;
        }

        fseek($resource, $savedPos, SEEK_SET);

        $mem->storeI32($nreadPtr, $totalRead);
        return $this->okRaw();
    }

    // ---- fd_pwrite ----

    private function fdPwrite(array $args): array
    {
        $fd          = $args[0]->value;
        $iovs        = $this->addr($args[1]);
        $iovsLen     = $args[2]->value;
        $offset      = $args[3]->value; // i64
        $nwrittenPtr = $this->addr($args[4]);

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->err(Errno::BADF);

        $mem          = $this->mem();
        $totalWritten = 0;

        $savedPos = ftell($resource);
        fseek($resource, $offset, SEEK_SET);

        for ($i = 0; $i < $iovsLen; $i++) {
            $base   = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($base);
            $bufLen = $mem->loadU32($base + 4);

            if ($bufLen === 0) continue;

            $data    = substr($mem->rawBytes(), $bufPtr, $bufLen);
            $written = @fwrite($resource, $data);
            if ($written === false) break;
            $totalWritten += $written;
        }

        fseek($resource, $savedPos, SEEK_SET);

        $mem->storeI32($nwrittenPtr, $totalWritten);
        return $this->ok();
    }

    private function fdPwriteRaw(array $args, int $base, int $count): int
    {
        $fd          = (int)($args[$base] ?? 0);
        $iovs        = self::rawAddr($args, $base, 1);
        $iovsLen     = (int)($args[$base + 2] ?? 0);
        $offset      = (int)($args[$base + 3] ?? 0);
        $nwrittenPtr = self::rawAddr($args, $base, 4);

        if (!$this->fdValid($fd)) return $this->errRaw(Errno::BADF);
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::ISDIR);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->errRaw(Errno::BADF);

        $mem          = $this->mem();
        $totalWritten = 0;

        $savedPos = ftell($resource);
        fseek($resource, $offset, SEEK_SET);

        for ($i = 0; $i < $iovsLen; $i++) {
            $iovBase = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($iovBase);
            $bufLen = $mem->loadU32($iovBase + 4);

            if ($bufLen === 0) continue;

            $data = substr($mem->rawBytes(), $bufPtr, $bufLen);
            $written = @fwrite($resource, $data);
            if ($written === false) break;
            $totalWritten += $written;
        }

        fseek($resource, $savedPos, SEEK_SET);

        $mem->storeI32($nwrittenPtr, $totalWritten);
        return $this->okRaw();
    }

    // ---- fd_close ----

    private function fdClose(array $args): array
    {
        $fd = $args[0]->value;

        // Stdio fds can be closed (renumber tests require this)
        if ($fd < 0) {
            return $this->err(Errno::BADF);
        }

        // Close preopen
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            unset($this->preopens[$fd]);
            unset($this->fdTypes[$fd]);
            unset($this->fdRightsBase[$fd]);
            unset($this->fdRightsInheriting[$fd]);
            unset($this->fdFlags[$fd]);
            return $this->ok();
        }

        // Close regular file
        if (isset($this->openFds[$fd])) {
            @fclose($this->openFds[$fd]);
            unset($this->openFds[$fd]);
            unset($this->preopens[$fd]);
            unset($this->fdTypes[$fd]);
            unset($this->fdRightsBase[$fd]);
            unset($this->fdRightsInheriting[$fd]);
            unset($this->fdFlags[$fd]);
            return $this->ok();
        }

        // Close stdio
        if ($fd <= 2) {
            // Mark as closed by clearing the rights
            unset($this->fdTypes[$fd]);
            unset($this->fdRightsBase[$fd]);
            unset($this->fdRightsInheriting[$fd]);
            return $this->ok();
        }

        return $this->err(Errno::BADF);
    }

    private function fdCloseRaw(array $args, int $base, int $count): int
    {
        $fd = (int)($args[$base] ?? 0);

        if ($fd < 0) {
            return $this->errRaw(Errno::BADF);
        }

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            unset($this->preopens[$fd], $this->fdTypes[$fd], $this->fdRightsBase[$fd], $this->fdRightsInheriting[$fd], $this->fdFlags[$fd]);
            return $this->okRaw();
        }

        if (isset($this->openFds[$fd])) {
            @fclose($this->openFds[$fd]);
            unset($this->openFds[$fd], $this->preopens[$fd], $this->fdTypes[$fd], $this->fdRightsBase[$fd], $this->fdRightsInheriting[$fd], $this->fdFlags[$fd]);
            return $this->okRaw();
        }

        if ($fd <= 2) {
            unset($this->fdTypes[$fd], $this->fdRightsBase[$fd], $this->fdRightsInheriting[$fd]);
            return $this->okRaw();
        }

        return $this->errRaw(Errno::BADF);
    }

    // ---- fd_seek ----

    private function fdSeek(array $args): array
    {
        $fd           = $args[0]->value;
        $offset       = $args[1]->value; // i64
        $whence       = $args[2]->value;
        $newoffsetPtr = $this->addr($args[3]);

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }
        if (!isset($this->openFds[$fd])) {
            return $this->err(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_SEEK)) {
            return $this->err(Errno::BADF);
        }

        $seekWhence = match ($whence) {
            self::WHENCE_SET => SEEK_SET,
            self::WHENCE_CUR => SEEK_CUR,
            self::WHENCE_END => SEEK_END,
            default          => null,
        };

        if ($seekWhence === null) {
            return $this->err(Errno::INVAL);
        }

        // Check for seek before start of file
        $resource = $this->openFds[$fd];
        if ($seekWhence === SEEK_SET && $offset < 0) {
            return $this->err(Errno::INVAL);
        }
        if ($seekWhence === SEEK_CUR) {
            $cur = ftell($resource);
            if ($cur + $offset < 0) {
                return $this->err(Errno::INVAL);
            }
        }

        if (fseek($resource, $offset, $seekWhence) !== 0) {
            return $this->err(Errno::INVAL);
        }

        $pos = ftell($resource);
        $this->mem()->storeI64($newoffsetPtr, (int)$pos);
        return $this->ok();
    }

    private function fdSeekRaw(array $args, int $base, int $count): int
    {
        $fd           = (int)($args[$base] ?? 0);
        $offset       = (int)($args[$base + 1] ?? 0);
        $whence       = (int)($args[$base + 2] ?? 0);
        $newoffsetPtr = self::rawAddr($args, $base, 3);

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::ISDIR);
        }
        if (!isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_SEEK)) {
            return $this->errRaw(Errno::BADF);
        }

        $seekWhence = match ($whence) {
            self::WHENCE_SET => SEEK_SET,
            self::WHENCE_CUR => SEEK_CUR,
            self::WHENCE_END => SEEK_END,
            default          => null,
        };

        if ($seekWhence === null) {
            return $this->errRaw(Errno::INVAL);
        }

        $resource = $this->openFds[$fd];
        if ($seekWhence === SEEK_SET && $offset < 0) {
            return $this->errRaw(Errno::INVAL);
        }
        if ($seekWhence === SEEK_CUR) {
            $cur = ftell($resource);
            if ($cur + $offset < 0) {
                return $this->errRaw(Errno::INVAL);
            }
        }

        if (fseek($resource, $offset, $seekWhence) !== 0) {
            return $this->errRaw(Errno::INVAL);
        }

        $pos = ftell($resource);
        $this->mem()->storeI64($newoffsetPtr, (int)$pos);
        return $this->okRaw();
    }

    // ---- fd_tell ----

    private function fdTell(array $args): array
    {
        $fd        = $args[0]->value;
        $offsetPtr = $this->addr($args[1]);

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }
        if (!isset($this->openFds[$fd])) {
            return $this->err(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_TELL)) {
            return $this->err(Errno::BADF);
        }

        $pos = ftell($this->openFds[$fd]);
        $this->mem()->storeI64($offsetPtr, (int)$pos);
        return $this->ok();
    }

    private function fdTellRaw(array $args, int $base, int $count): int
    {
        $fd        = (int)($args[$base] ?? 0);
        $offsetPtr = self::rawAddr($args, $base, 1);

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::ISDIR);
        }
        if (!isset($this->openFds[$fd])) {
            return $this->errRaw(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_TELL)) {
            return $this->errRaw(Errno::BADF);
        }

        $pos = ftell($this->openFds[$fd]);
        $this->mem()->storeI64($offsetPtr, (int)$pos);
        return $this->okRaw();
    }

    // ---- fd_sync / fd_datasync ----

    private function fdSync(array $args): array
    {
        $fd = $args[0]->value;
        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        $res = $this->openFds[$fd] ?? null;
        if ($res !== null) @fflush($res);
        return $this->ok();
    }

    private function fdDatasync(array $args): array
    {
        return $this->fdSync($args);
    }

    // ---- fd_fdstat_get ----

    private function fdFdstatGet(array $args): array
    {
        $fd      = $args[0]->value;
        $statPtr = $this->addr($args[1]);

        if (!$this->fdValid($fd)) {
            return $this->err(Errno::BADF);
        }

        $mem = $this->mem();

        $fileType = $this->fdTypes[$fd] ?? self::FILETYPE_UNKNOWN;
        $flags    = $this->fdFlags[$fd] ?? 0;
        $rightsBase       = $this->fdRightsBase[$fd] ?? self::RIGHTS_ALL;
        $rightsInheriting = $this->fdRightsInheriting[$fd] ?? self::RIGHTS_ALL;

        // fdstat struct (24 bytes):
        //  0: fs_filetype (u8)
        //  2: fs_flags (u16) — aligned at offset 2
        //  8: fs_rights_base (u64)
        // 16: fs_rights_inheriting (u64)
        $mem->storeI8($statPtr, $fileType);
        $mem->storeI8($statPtr + 1, 0); // padding
        $mem->storeI8($statPtr + 2, $flags & 0xFF);
        $mem->storeI8($statPtr + 3, ($flags >> 8) & 0xFF);
        // padding bytes 4-7
        for ($j = 4; $j < 8; $j++) {
            $mem->storeI8($statPtr + $j, 0);
        }
        $mem->storeI64($statPtr + 8, $rightsBase);
        $mem->storeI64($statPtr + 16, $rightsInheriting);

        return $this->ok();
    }

    private function fdFdstatGetRaw(array $args, int $base, int $count): int
    {
        $fd      = (int)($args[$base] ?? 0);
        $statPtr = self::rawAddr($args, $base, 1);

        if (!$this->fdValid($fd)) {
            return $this->errRaw(Errno::BADF);
        }

        $mem = $this->mem();
        $fileType = $this->fdTypes[$fd] ?? self::FILETYPE_UNKNOWN;
        $flags    = $this->fdFlags[$fd] ?? 0;
        $rightsBase       = $this->fdRightsBase[$fd] ?? self::RIGHTS_ALL;
        $rightsInheriting = $this->fdRightsInheriting[$fd] ?? self::RIGHTS_ALL;

        $mem->storeI8($statPtr, $fileType);
        $mem->storeI8($statPtr + 1, 0);
        $mem->storeI8($statPtr + 2, $flags & 0xFF);
        $mem->storeI8($statPtr + 3, ($flags >> 8) & 0xFF);
        for ($j = 4; $j < 8; $j++) {
            $mem->storeI8($statPtr + $j, 0);
        }
        $mem->storeI64($statPtr + 8, $rightsBase);
        $mem->storeI64($statPtr + 16, $rightsInheriting);

        return $this->okRaw();
    }

    // ---- fd_fdstat_set_flags ----

    private function fdFdstatSetFlags(array $args): array
    {
        $fd    = $args[0]->value;
        $flags = $args[1]->value;

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (!$this->hasRight($fd, self::RIGHT_FD_FDSTAT_SET_FLAGS)) {
            return $this->err(Errno::BADF);
        }

        // If changing append flag on an open file, reopen with new mode
        $resource = $this->openFds[$fd] ?? null;
        if ($resource !== null) {
            $meta = stream_get_meta_data($resource);
            $path = $meta['uri'] ?? null;
            if ($path !== null) {
                $newAppend = ($flags & self::FDFLAGS_APPEND) !== 0;
                $oldAppend = (($this->fdFlags[$fd] ?? 0) & self::FDFLAGS_APPEND) !== 0;
                if ($newAppend !== $oldAppend) {
                    $pos = ftell($resource);
                    $wantsRead = true; // Assume r+w for simplicity
                    fclose($resource);
                    $mode = $newAppend ? 'a+b' : 'r+b';
                    $newHandle = @fopen($path, $mode);
                    if ($newHandle !== false) {
                        $this->openFds[$fd] = $newHandle;
                        if (!$newAppend) {
                            fseek($newHandle, $pos, SEEK_SET);
                        }
                    }
                }
            }
        }

        $this->fdFlags[$fd] = $flags;
        return $this->ok();
    }

    // ---- fd_fdstat_set_rights ----

    private function fdFdstatSetRights(array $args): array
    {
        $fd               = $args[0]->value;
        $newRightsBase    = $args[1]->value; // i64
        $newRightsInherit = $args[2]->value; // i64

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);

        $currentBase    = $this->fdRightsBase[$fd] ?? self::RIGHTS_ALL;
        $currentInherit = $this->fdRightsInheriting[$fd] ?? self::RIGHTS_ALL;

        // Can only remove rights, not add new ones
        if (($newRightsBase & ~$currentBase) !== 0) {
            return $this->err(Errno::NOTCAPABLE);
        }
        if (($newRightsInherit & ~$currentInherit) !== 0) {
            return $this->err(Errno::NOTCAPABLE);
        }

        $this->fdRightsBase[$fd] = $newRightsBase;
        $this->fdRightsInheriting[$fd] = $newRightsInherit;
        return $this->ok();
    }

    // ---- fd_filestat_get ----

    private function fdFilestatGet(array $args): array
    {
        $fd      = $args[0]->value;
        $statPtr = $this->addr($args[1]);

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (!$this->hasRight($fd, self::RIGHT_FD_FILESTAT_GET)) {
            return $this->err(Errno::BADF);
        }

        // For preopens (directories), stat the directory
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            $path = $this->preopens[$fd];
            $stat = @stat($path);
            if ($stat === false) return $this->err(Errno::IO);
            $this->writeFilestat($statPtr, $stat, self::FILETYPE_DIRECTORY, $path);
            return $this->ok();
        }

        // For open files, use fstat
        $resource = $this->fdResource($fd);
        if ($resource === null) return $this->err(Errno::BADF);

        if (is_resource($resource)) {
            $stat = @fstat($resource);
            if ($stat === false) return $this->err(Errno::IO);
            $filetype = $this->fdTypes[$fd] ?? self::FILETYPE_REGULAR_FILE;
            // Get file path for nanosecond-precision timestamps
            $filePath = null;
            $meta = @stream_get_meta_data($resource);
            if ($meta !== false && isset($meta['uri'])) {
                $filePath = $meta['uri'];
            }
            $this->writeFilestat($statPtr, $stat, $filetype, $filePath);
            return $this->ok();
        }

        return $this->err(Errno::BADF);
    }

    private function fdFilestatGetRaw(array $args, int $base, int $count): int
    {
        $fd      = (int)($args[$base] ?? 0);
        $statPtr = self::rawAddr($args, $base, 1);

        if (!$this->fdValid($fd)) return $this->errRaw(Errno::BADF);
        if (!$this->hasRight($fd, self::RIGHT_FD_FILESTAT_GET)) {
            return $this->errRaw(Errno::BADF);
        }

        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            $path = $this->preopens[$fd];
            $stat = @stat($path);
            if ($stat === false) return $this->errRaw(Errno::IO);
            $this->writeFilestat($statPtr, $stat, self::FILETYPE_DIRECTORY, $path);
            return $this->okRaw();
        }

        $resource = $this->fdResource($fd);
        if ($resource === null) return $this->errRaw(Errno::BADF);

        if (is_resource($resource)) {
            $stat = @fstat($resource);
            if ($stat === false) return $this->errRaw(Errno::IO);
            $filetype = $this->fdTypes[$fd] ?? self::FILETYPE_REGULAR_FILE;
            $filePath = null;
            $meta = @stream_get_meta_data($resource);
            if ($meta !== false && isset($meta['uri'])) {
                $filePath = $meta['uri'];
            }
            $this->writeFilestat($statPtr, $stat, $filetype, $filePath);
            return $this->okRaw();
        }

        return $this->errRaw(Errno::BADF);
    }

    // ---- fd_filestat_set_size ----

    private function fdFilestatSetSize(array $args): array
    {
        $fd   = $args[0]->value;
        $size = $args[1]->value; // i64

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_FILESTAT_SET_SIZE)) {
            return $this->err(Errno::BADF);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->err(Errno::BADF);

        if (!ftruncate($resource, $size)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- fd_filestat_set_times ----

    private function fdFilestatSetTimes(array $args): array
    {
        $fd      = $args[0]->value;
        $atim    = $args[1]->value; // i64 nanoseconds
        $mtim    = $args[2]->value; // i64 nanoseconds
        $fstflags = $args[3]->value;

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (!$this->hasRight($fd, self::RIGHT_FD_FILESTAT_SET_TIMES)) {
            return $this->err(Errno::BADF);
        }

        // Validate: can't set both ATIM and ATIM_NOW, or MTIM and MTIM_NOW
        if (($fstflags & self::FSTFLAGS_ATIM) && ($fstflags & self::FSTFLAGS_ATIM_NOW)) {
            return $this->err(Errno::INVAL);
        }
        if (($fstflags & self::FSTFLAGS_MTIM) && ($fstflags & self::FSTFLAGS_MTIM_NOW)) {
            return $this->err(Errno::INVAL);
        }

        // Resolve file path for touch
        $path = null;
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            $path = $this->preopens[$fd];
        } elseif (isset($this->openFds[$fd])) {
            $meta = stream_get_meta_data($this->openFds[$fd]);
            $path = $meta['uri'] ?? null;
        }
        if ($path === null) return $this->err(Errno::BADF);

        // Get current timestamps in nanoseconds
        $nsData = $this->statNs($path);
        if ($nsData !== null) {
            $curAtimeNs = $nsData['atime_ns'];
            $curMtimeNs = $nsData['mtime_ns'];
        } else {
            $currentStat = @stat($path);
            $curAtimeNs = ($currentStat ? $currentStat['atime'] : time()) * 1_000_000_000;
            $curMtimeNs = ($currentStat ? $currentStat['mtime'] : time()) * 1_000_000_000;
        }

        $nowNs = (int)(microtime(true) * 1_000_000_000);
        $newAtimeNs = $curAtimeNs;
        $newMtimeNs = $curMtimeNs;

        if ($fstflags & self::FSTFLAGS_ATIM_NOW) {
            $newAtimeNs = $nowNs;
        } elseif ($fstflags & self::FSTFLAGS_ATIM) {
            $newAtimeNs = $atim;
        }

        if ($fstflags & self::FSTFLAGS_MTIM_NOW) {
            $newMtimeNs = $nowNs;
        } elseif ($fstflags & self::FSTFLAGS_MTIM) {
            $newMtimeNs = $mtim;
        }

        $this->utimeNs($path, $newAtimeNs, $newMtimeNs);
        return $this->ok();
    }

    // ---- fd_advise ----

    private function fdAdvise(array $args): array
    {
        $fd = $args[0]->value;
        // offset = $args[1], len = $args[2], advice = $args[3] — all ignored
        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        // fd_advise is advisory only, always succeeds
        return $this->ok();
    }

    // ---- fd_allocate ----

    private function fdAllocate(array $args): array
    {
        $fd     = $args[0]->value;
        $offset = $args[1]->value; // i64
        $len    = $args[2]->value; // i64

        if (!$this->fdValid($fd)) return $this->err(Errno::BADF);
        if (!$this->hasRight($fd, self::RIGHT_FD_ALLOCATE)) {
            return $this->err(Errno::BADF);
        }
        if (isset($this->preopens[$fd]) && !isset($this->openFds[$fd])) {
            return $this->err(Errno::ISDIR);
        }

        $resource = $this->openFds[$fd] ?? null;
        if ($resource === null) return $this->err(Errno::BADF);

        // Extend file if needed
        $stat = fstat($resource);
        $needed = $offset + $len;
        if ($needed > $stat['size']) {
            ftruncate($resource, $needed);
        }
        return $this->ok();
    }

    // ---- fd_readdir ----

    private function fdReaddir(array $args): array
    {
        $fd        = $args[0]->value;
        $bufPtr    = $this->addr($args[1]);
        $bufLen    = $args[2]->value;
        $cookie    = $args[3]->value; // i64
        $usedPtr   = $this->addr($args[4]);

        if (!isset($this->preopens[$fd])) {
            return $this->err(Errno::BADF);
        }
        if (!$this->hasRight($fd, self::RIGHT_FD_READDIR)) {
            return $this->err(Errno::BADF);
        }

        $dirPath = $this->preopens[$fd];
        $entries = @scandir($dirPath);
        if ($entries === false) {
            return $this->err(Errno::IO);
        }

        // Sort and prepend . and .. (scandir includes them but we ensure order)
        $dotEntries = [];
        $otherEntries = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                $dotEntries[] = $e;
            } else {
                $otherEntries[] = $e;
            }
        }
        sort($dotEntries);
        sort($otherEntries);
        $allEntries = array_merge($dotEntries, $otherEntries);

        $mem    = $this->mem();
        $offset = 0;
        $idx    = 0;

        foreach ($allEntries as $entry) {
            if ($idx < $cookie) {
                $idx++;
                continue;
            }

            $entryPath = rtrim($dirPath, '/') . '/' . $entry;
            $stat = @stat($entryPath);
            $ino  = $stat ? $stat['ino'] : 0;

            $filetype = self::FILETYPE_UNKNOWN;
            if ($entry === '.' || $entry === '..') {
                $filetype = self::FILETYPE_DIRECTORY;
            } elseif (is_link($entryPath)) {
                $filetype = self::FILETYPE_SYMBOLIC_LINK;
            } elseif (is_dir($entryPath)) {
                $filetype = self::FILETYPE_DIRECTORY;
            } elseif (is_file($entryPath)) {
                $filetype = self::FILETYPE_REGULAR_FILE;
            }

            $nameBytes = $entry;
            $nameLen   = strlen($nameBytes);

            // dirent struct: next(u64) + ino(u64) + namelen(u32) + type(u8) = 24 bytes header + name
            $headerSize = 24;
            $entrySize  = $headerSize + $nameLen;

            if ($offset + $headerSize <= $bufLen) {
                $base = $bufPtr + $offset;
                $mem->storeI64($base, $idx + 1);           // d_next (cookie for next entry)
                $mem->storeI64($base + 8, $ino);            // d_ino
                $mem->storeI32($base + 16, $nameLen);       // d_namlen
                $mem->storeI8($base + 20, $filetype);       // d_type
                // padding 21-23
                $mem->storeI8($base + 21, 0);
                $mem->storeI8($base + 22, 0);
                $mem->storeI8($base + 23, 0);

                // Write as much of the name as fits
                $nameSpace = min($nameLen, $bufLen - $offset - $headerSize);
                if ($nameSpace > 0) {
                    $mem->init($base + $headerSize, substr($nameBytes, 0, $nameSpace));
                }
            }

            $offset += $entrySize;
            $idx++;
        }

        $mem->storeI32($usedPtr, min($offset, $bufLen));
        return $this->ok();
    }

    // ---- fd_renumber ----

    private function fdRenumber(array $args): array
    {
        $from = $args[0]->value;
        $to   = $args[1]->value;

        if (!$this->fdValid($from)) return $this->err(Errno::BADF);
        if (!$this->fdValid($to))   return $this->err(Errno::BADF);

        // Close the target fd first
        if (isset($this->openFds[$to])) {
            @fclose($this->openFds[$to]);
            unset($this->openFds[$to]);
        }

        // Move all metadata from source to target
        if (isset($this->openFds[$from])) {
            $this->openFds[$to] = $this->openFds[$from];
            unset($this->openFds[$from]);
        }
        if (isset($this->preopens[$from])) {
            $this->preopens[$to] = $this->preopens[$from];
            unset($this->preopens[$from]);
        } else {
            unset($this->preopens[$to]);
        }

        $this->fdTypes[$to] = $this->fdTypes[$from] ?? self::FILETYPE_UNKNOWN;
        $this->fdRightsBase[$to] = $this->fdRightsBase[$from] ?? self::RIGHTS_ALL;
        $this->fdRightsInheriting[$to] = $this->fdRightsInheriting[$from] ?? self::RIGHTS_ALL;
        $this->fdFlags[$to] = $this->fdFlags[$from] ?? 0;

        // Source fd is now invalid
        unset($this->fdTypes[$from]);
        unset($this->fdRightsBase[$from]);
        unset($this->fdRightsInheriting[$from]);
        unset($this->fdFlags[$from]);

        return $this->ok();
    }

    // ---- fd_prestat_get ----

    private function fdPrestatGet(array $args): array
    {
        $fd         = $args[0]->value;
        $prestatPtr = $this->addr($args[1]);

        if (!isset($this->preopens[$fd])) {
            return $this->err(Errno::BADF);
        }

        $mem = $this->mem();
        $mem->storeI32($prestatPtr, 0); // tag = dir (u8, but aligned to u32)
        $mem->storeI32($prestatPtr + 4, strlen($this->preopens[$fd]));

        return $this->ok();
    }

    private function fdPrestatGetRaw(array $args, int $base, int $count): int
    {
        $fd         = (int)($args[$base] ?? 0);
        $prestatPtr = self::rawAddr($args, $base, 1);

        if (!isset($this->preopens[$fd])) {
            return $this->errRaw(Errno::BADF);
        }

        $mem = $this->mem();
        $mem->storeI32($prestatPtr, 0);
        $mem->storeI32($prestatPtr + 4, strlen($this->preopens[$fd]));

        return $this->okRaw();
    }

    // ---- fd_prestat_dir_name ----

    private function fdPrestatDirName(array $args): array
    {
        $fd      = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;

        if (!isset($this->preopens[$fd])) {
            return $this->err(Errno::BADF);
        }

        $name = $this->preopens[$fd];
        if ($pathLen < strlen($name)) {
            return $this->err(Errno::NAMETOOLONG);
        }

        $this->mem()->init($pathPtr, substr($name, 0, $pathLen));
        return $this->ok();
    }

    private function fdPrestatDirNameRaw(array $args, int $base, int $count): int
    {
        $fd      = (int)($args[$base] ?? 0);
        $pathPtr = self::rawAddr($args, $base, 1);
        $pathLen = (int)($args[$base + 2] ?? 0);

        if (!isset($this->preopens[$fd])) {
            return $this->errRaw(Errno::BADF);
        }

        $name = $this->preopens[$fd];
        if ($pathLen < strlen($name)) {
            return $this->errRaw(Errno::NAMETOOLONG);
        }

        $this->mem()->init($pathPtr, substr($name, 0, $pathLen));
        return $this->okRaw();
    }

    // ---- path_open ----

    private function pathOpen(array $args): array
    {
        $dirfd       = $args[0]->value;
        $dirflags    = $args[1]->value; // lookupflags
        $pathPtr     = $this->addr($args[2]);
        $pathLen     = $args[3]->value;
        $oflags      = $args[4]->value;
        $fsRightsBase       = $args[5]->value; // i64
        $fsRightsInheriting = $args[6]->value; // i64
        $fdflags     = $args[7]->value;
        $fdPtr       = $this->addr($args[8]);

        // dirfd must be a directory
        if (!isset($this->preopens[$dirfd])) {
            if (isset($this->openFds[$dirfd])) {
                return $this->err(Errno::NOTDIR);
            }
            return $this->err(Errno::BADF);
        }

        $mem     = $this->mem();
        $relPath = substr($mem->rawBytes(), $pathPtr, $pathLen);

        // Validate path
        if (str_contains($relPath, "\0")) {
            return $this->err(Errno::INVAL);
        }

        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        $followSymlinks = ($dirflags & self::LOOKUPFLAGS_SYMLINK_FOLLOW) !== 0;

        $isDirectory = ($oflags & self::OFLAGS_DIRECTORY) !== 0;
        $isCreate    = ($oflags & self::OFLAGS_CREAT) !== 0;
        $isExclusive = ($oflags & self::OFLAGS_EXCL) !== 0;
        $isTruncate  = ($oflags & self::OFLAGS_TRUNC) !== 0;

        $hasAppend = ($fdflags & self::FDFLAGS_APPEND) !== 0;

        // Sandbox check (rejects absolute paths and too many ..)
        $sandboxErr = $this->checkSandbox($dirfd, $relPath);
        if ($sandboxErr !== null) return $this->err($sandboxErr);

        // Check truncation rights
        if ($isTruncate) {
            if (!$this->hasRight($dirfd, self::RIGHT_PATH_FILESTAT_SET_SIZE)) {
                return $this->err(Errno::PERM);
            }
        }

        // Check for symlinks when not following
        if (!$followSymlinks && is_link($absPath)) {
            return $this->err(Errno::LOOP);
        }

        // Opening a directory
        if ($isDirectory) {
            if (is_file($absPath)) {
                return $this->err(Errno::NOTDIR);
            }
            if (!is_dir($absPath)) {
                return $this->err(Errno::NOENT);
            }
            // Opening directory for read — check if they want write too
            $wantsWrite = ($fsRightsBase & self::RIGHT_FD_WRITE) !== 0;
            if ($wantsWrite) {
                return $this->err(Errno::ISDIR);
            }

            $fd = $this->nextFd++;
            $this->preopens[$fd] = $absPath;
            $this->fdTypes[$fd] = self::FILETYPE_DIRECTORY;
            // Directory rights: inherit from parent but intersect with requested
            $parentRightsBase = $this->fdRightsInheriting[$dirfd] ?? self::RIGHTS_ALL;
            $this->fdRightsBase[$fd] = $parentRightsBase & self::RIGHTS_DIR_BASE;
            $this->fdRightsInheriting[$fd] = $parentRightsBase & (self::RIGHTS_DIR_BASE | self::RIGHTS_FILE_BASE);
            $this->fdFlags[$fd] = $fdflags;
            $mem->storeI32($fdPtr, $fd);
            return $this->ok();
        }

        // Handle trailing slashes on non-directory paths
        if (str_ends_with($relPath, '/')) {
            if (is_file($absPath)) {
                return $this->err(Errno::NOTDIR);
            }
        }

        // Exclusive create: file must not exist
        if ($isCreate && $isExclusive && file_exists($absPath)) {
            return $this->err(Errno::EXIST);
        }

        // Non-create open: file must exist
        if (!$isCreate && !file_exists($absPath)) {
            return $this->err(Errno::NOENT);
        }

        // Determine read/write from requested rights
        $wantsRead  = ($fsRightsBase & self::RIGHT_FD_READ) !== 0;
        $wantsWrite = ($fsRightsBase & self::RIGHT_FD_WRITE) !== 0;

        // Determine fopen mode
        if ($isCreate && $isExclusive) {
            $mode = $wantsRead ? 'x+b' : 'xb';
        } elseif ($isCreate && $isTruncate) {
            $mode = $wantsRead ? 'w+b' : 'wb';
        } elseif ($isTruncate) {
            $mode = $wantsRead ? 'w+b' : 'wb';
        } elseif ($isCreate && $hasAppend) {
            $mode = $wantsRead ? 'a+b' : 'ab';
        } elseif ($isCreate) {
            if (!file_exists($absPath)) {
                $mode = $wantsRead ? 'w+b' : 'wb';
            } else {
                $mode = ($wantsRead && $wantsWrite) ? 'r+b' : ($wantsWrite ? 'r+b' : 'rb');
            }
        } elseif ($hasAppend) {
            $mode = $wantsRead ? 'a+b' : 'ab';
        } elseif ($wantsRead && $wantsWrite) {
            $mode = 'r+b';
        } elseif ($wantsWrite) {
            $mode = 'r+b';
        } else {
            $mode = 'rb';
        }

        $handle = @fopen($absPath, $mode);
        if ($handle === false) {
            if (!file_exists($absPath)) return $this->err(Errno::NOENT);
            if (is_dir($absPath))       return $this->err(Errno::ISDIR);
            return $this->err(Errno::ACCES);
        }

        $fd = $this->nextFd++;
        $this->openFds[$fd] = $handle;
        $this->fdTypes[$fd] = self::FILETYPE_REGULAR_FILE;
        // Intersect requested rights with inheriting rights from parent
        $parentRightsInheriting = $this->fdRightsInheriting[$dirfd] ?? self::RIGHTS_ALL;
        $this->fdRightsBase[$fd] = $fsRightsBase & $parentRightsInheriting;
        $this->fdRightsInheriting[$fd] = $fsRightsInheriting & $parentRightsInheriting;
        $this->fdFlags[$fd] = $fdflags;
        $mem->storeI32($fdPtr, $fd);
        return $this->ok();
    }

    private function pathOpenRaw(array $args, int $base, int $count): int
    {
        $dirfd       = (int)($args[$base] ?? 0);
        $dirflags    = (int)($args[$base + 1] ?? 0);
        $pathPtr     = self::rawAddr($args, $base, 2);
        $pathLen     = (int)($args[$base + 3] ?? 0);
        $oflags      = (int)($args[$base + 4] ?? 0);
        $fsRightsBase       = (int)($args[$base + 5] ?? 0);
        $fsRightsInheriting = (int)($args[$base + 6] ?? 0);
        $fdflags     = (int)($args[$base + 7] ?? 0);
        $fdPtr       = self::rawAddr($args, $base, 8);

        if (!isset($this->preopens[$dirfd])) {
            if (isset($this->openFds[$dirfd])) {
                return $this->errRaw(Errno::NOTDIR);
            }
            return $this->errRaw(Errno::BADF);
        }

        $mem     = $this->mem();
        $relPath = substr($mem->rawBytes(), $pathPtr, $pathLen);

        if (str_contains($relPath, "\0")) {
            return $this->errRaw(Errno::INVAL);
        }

        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->errRaw(Errno::BADF);

        $followSymlinks = ($dirflags & self::LOOKUPFLAGS_SYMLINK_FOLLOW) !== 0;
        $isDirectory = ($oflags & self::OFLAGS_DIRECTORY) !== 0;
        $isCreate    = ($oflags & self::OFLAGS_CREAT) !== 0;
        $isExclusive = ($oflags & self::OFLAGS_EXCL) !== 0;
        $isTruncate  = ($oflags & self::OFLAGS_TRUNC) !== 0;
        $hasAppend = ($fdflags & self::FDFLAGS_APPEND) !== 0;

        $sandboxErr = $this->checkSandbox($dirfd, $relPath);
        if ($sandboxErr !== null) return $this->errRaw($sandboxErr);

        if ($isTruncate && !$this->hasRight($dirfd, self::RIGHT_PATH_FILESTAT_SET_SIZE)) {
            return $this->errRaw(Errno::PERM);
        }

        if (!$followSymlinks && is_link($absPath)) {
            return $this->errRaw(Errno::LOOP);
        }

        if ($isDirectory) {
            if (is_file($absPath)) {
                return $this->errRaw(Errno::NOTDIR);
            }
            if (!is_dir($absPath)) {
                return $this->errRaw(Errno::NOENT);
            }
            $wantsWrite = ($fsRightsBase & self::RIGHT_FD_WRITE) !== 0;
            if ($wantsWrite) {
                return $this->errRaw(Errno::ISDIR);
            }

            $fd = $this->nextFd++;
            $this->preopens[$fd] = $absPath;
            $this->fdTypes[$fd] = self::FILETYPE_DIRECTORY;
            $parentRightsBase = $this->fdRightsInheriting[$dirfd] ?? self::RIGHTS_ALL;
            $this->fdRightsBase[$fd] = $parentRightsBase & self::RIGHTS_DIR_BASE;
            $this->fdRightsInheriting[$fd] = $parentRightsBase & (self::RIGHTS_DIR_BASE | self::RIGHTS_FILE_BASE);
            $this->fdFlags[$fd] = $fdflags;
            $mem->storeI32($fdPtr, $fd);
            return $this->okRaw();
        }

        if (str_ends_with($relPath, '/')) {
            if (is_file($absPath)) {
                return $this->errRaw(Errno::NOTDIR);
            }
        }

        if ($isCreate && $isExclusive && file_exists($absPath)) {
            return $this->errRaw(Errno::EXIST);
        }

        if (!$isCreate && !file_exists($absPath)) {
            return $this->errRaw(Errno::NOENT);
        }

        $wantsRead  = ($fsRightsBase & self::RIGHT_FD_READ) !== 0;
        $wantsWrite = ($fsRightsBase & self::RIGHT_FD_WRITE) !== 0;

        if ($isCreate && $isExclusive) {
            $mode = $wantsRead ? 'x+b' : 'xb';
        } elseif ($isCreate && $isTruncate) {
            $mode = $wantsRead ? 'w+b' : 'wb';
        } elseif ($isTruncate) {
            $mode = $wantsRead ? 'w+b' : 'wb';
        } elseif ($isCreate && $hasAppend) {
            $mode = $wantsRead ? 'a+b' : 'ab';
        } elseif ($isCreate) {
            if (!file_exists($absPath)) {
                $mode = $wantsRead ? 'w+b' : 'wb';
            } else {
                $mode = ($wantsRead && $wantsWrite) ? 'r+b' : ($wantsWrite ? 'r+b' : 'rb');
            }
        } elseif ($hasAppend) {
            $mode = $wantsRead ? 'a+b' : 'ab';
        } elseif ($wantsRead && $wantsWrite) {
            $mode = 'r+b';
        } elseif ($wantsWrite) {
            $mode = 'r+b';
        } else {
            $mode = 'rb';
        }

        $handle = @fopen($absPath, $mode);
        if ($handle === false) {
            if (!file_exists($absPath)) return $this->errRaw(Errno::NOENT);
            if (is_dir($absPath))       return $this->errRaw(Errno::ISDIR);
            return $this->errRaw(Errno::ACCES);
        }

        $fd = $this->nextFd++;
        $this->openFds[$fd] = $handle;
        $this->fdTypes[$fd] = self::FILETYPE_REGULAR_FILE;
        $parentRightsInheriting = $this->fdRightsInheriting[$dirfd] ?? self::RIGHTS_ALL;
        $this->fdRightsBase[$fd] = $fsRightsBase & $parentRightsInheriting;
        $this->fdRightsInheriting[$fd] = $fsRightsInheriting & $parentRightsInheriting;
        $this->fdFlags[$fd] = $fdflags;
        $mem->storeI32($fdPtr, $fd);
        return $this->okRaw();
    }

    // ---- path_create_directory ----

    private function pathCreateDirectory(array $args): array
    {
        $dirfd   = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);
        if (!$this->hasRight($dirfd, self::RIGHT_PATH_CREATE_DIRECTORY)) {
            return $this->err(Errno::NOTCAPABLE);
        }

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        if (str_contains($relPath, "\0")) return $this->err(Errno::INVAL);

        $sandboxErr = $this->checkSandbox($dirfd, $relPath);
        if ($sandboxErr !== null) return $this->err($sandboxErr);

        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        if (file_exists($absPath)) return $this->err(Errno::EXIST);

        if (!@mkdir($absPath, 0777, true)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- path_remove_directory ----

    private function pathRemoveDirectory(array $args): array
    {
        $dirfd   = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);
        if (!$this->hasRight($dirfd, self::RIGHT_PATH_REMOVE_DIRECTORY)) {
            return $this->err(Errno::NOTCAPABLE);
        }

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        if (!is_dir($absPath)) {
            if (is_file($absPath) || is_link($absPath)) {
                return $this->err(Errno::NOTDIR);
            }
            return $this->err(Errno::NOENT);
        }

        // Check if directory is empty
        $contents = @scandir($absPath);
        if ($contents !== false && count($contents) > 2) { // . and ..
            return $this->err(Errno::NOTEMPTY);
        }

        if (!@rmdir($absPath)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- path_unlink_file ----

    private function pathUnlinkFile(array $args): array
    {
        $dirfd   = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);
        if (!$this->hasRight($dirfd, self::RIGHT_PATH_UNLINK_FILE)) {
            return $this->err(Errno::NOTCAPABLE);
        }

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        // Cannot unlink directories
        if (is_dir($absPath) && !is_link($absPath)) {
            return $this->err(Errno::ISDIR);
        }

        if (!file_exists($absPath) && !is_link($absPath)) {
            return $this->err(Errno::NOENT);
        }

        if (!@unlink($absPath)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- path_rename ----

    private function pathRename(array $args): array
    {
        $oldDirfd = $args[0]->value;
        $oldPtr   = $this->addr($args[1]);
        $oldLen   = $args[2]->value;
        $newDirfd = $args[3]->value;
        $newPtr   = $this->addr($args[4]);
        $newLen   = $args[5]->value;

        if (!isset($this->preopens[$oldDirfd])) return $this->err(Errno::BADF);
        if (!isset($this->preopens[$newDirfd])) return $this->err(Errno::BADF);

        $mem     = $this->mem();
        $oldRel  = substr($mem->rawBytes(), $oldPtr, $oldLen);
        $newRel  = substr($mem->rawBytes(), $newPtr, $newLen);
        $oldPath = $this->resolvePath($oldDirfd, $oldRel);
        $newPath = $this->resolvePath($newDirfd, $newRel);
        if ($oldPath === null || $newPath === null) return $this->err(Errno::BADF);

        if (!file_exists($oldPath) && !is_link($oldPath)) {
            return $this->err(Errno::NOENT);
        }

        // Can't rename file to existing directory
        if (is_file($oldPath) && is_dir($newPath)) {
            return $this->err(Errno::ISDIR);
        }

        // Can't rename directory to existing file
        if (is_dir($oldPath) && is_file($newPath)) {
            return $this->err(Errno::NOTDIR);
        }

        // Can't rename directory to non-empty directory
        if (is_dir($oldPath) && is_dir($newPath)) {
            $contents = @scandir($newPath);
            if ($contents !== false && count($contents) > 2) {
                return $this->err(Errno::NOTEMPTY);
            }
        }

        if (!@rename($oldPath, $newPath)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- path_symlink ----

    private function pathSymlink(array $args): array
    {
        $oldPathPtr = $this->addr($args[0]);
        $oldPathLen = $args[1]->value;
        $dirfd      = $args[2]->value;
        $newPathPtr = $this->addr($args[3]);
        $newPathLen = $args[4]->value;

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);
        if (!$this->hasRight($dirfd, self::RIGHT_PATH_SYMLINK)) {
            return $this->err(Errno::NOTCAPABLE);
        }

        $mem     = $this->mem();
        $target  = substr($mem->rawBytes(), $oldPathPtr, $oldPathLen);
        $linkRel = substr($mem->rawBytes(), $newPathPtr, $newPathLen);

        // Reject link names ending with /
        if (str_ends_with($linkRel, '/')) {
            return $this->err(Errno::NOENT);
        }

        $linkPath = $this->resolvePath($dirfd, $linkRel);
        if ($linkPath === null) return $this->err(Errno::BADF);

        // Reject absolute targets
        if (str_starts_with($target, '/')) {
            return $this->err(Errno::PERM);
        }

        if (file_exists($linkPath) || is_link($linkPath)) {
            return $this->err(Errno::EXIST);
        }

        if (!@symlink($target, $linkPath)) {
            return $this->err(Errno::IO);
        }
        return $this->ok();
    }

    // ---- path_readlink ----

    private function pathReadlink(array $args): array
    {
        $dirfd   = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;
        $bufPtr  = $this->addr($args[3]);
        $bufLen  = $args[4]->value;
        $usedPtr = $this->addr($args[5]);

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        $target = @readlink($absPath);
        if ($target === false) {
            return $this->err(Errno::INVAL);
        }

        $mem  = $this->mem();
        $used = min(strlen($target), $bufLen);
        if ($used > 0) {
            $mem->init($bufPtr, substr($target, 0, $used));
        }
        $mem->storeI32($usedPtr, $used);
        return $this->ok();
    }

    // ---- path_link ----

    private function pathLink(array $args): array
    {
        $oldDirfd = $args[0]->value;
        $oldFlags = $args[1]->value; // lookupflags
        $oldPtr   = $this->addr($args[2]);
        $oldLen   = $args[3]->value;
        $newDirfd = $args[4]->value;
        $newPtr   = $this->addr($args[5]);
        $newLen   = $args[6]->value;

        if (!isset($this->preopens[$oldDirfd])) return $this->err(Errno::BADF);
        if (!isset($this->preopens[$newDirfd])) return $this->err(Errno::BADF);

        $mem     = $this->mem();
        $oldRel  = substr($mem->rawBytes(), $oldPtr, $oldLen);
        $newRel  = substr($mem->rawBytes(), $newPtr, $newLen);
        $oldPath = $this->resolvePath($oldDirfd, $oldRel);
        $newPath = $this->resolvePath($newDirfd, $newRel);
        if ($oldPath === null || $newPath === null) return $this->err(Errno::BADF);

        // Determine if we should follow symlinks (create hardlink to target)
        $followSymlinks = ($oldFlags & self::LOOKUPFLAGS_SYMLINK_FOLLOW) !== 0;

        if ($followSymlinks && is_link($oldPath)) {
            // Following a dangling symlink → target doesn't exist
            $target = @readlink($oldPath);
            if ($target === false || !file_exists($oldPath)) {
                return $this->err(Errno::NOENT);
            }
        }

        if (!file_exists($oldPath) && !is_link($oldPath)) return $this->err(Errno::NOENT);
        if (is_dir($oldPath) && !is_link($oldPath)) return $this->err(Errno::PERM);

        // Trailing slash in new path — the parent must exist
        if (str_ends_with($newRel, '/')) {
            return $this->err(Errno::NOENT);
        }

        if (file_exists($newPath) || is_link($newPath)) return $this->err(Errno::EXIST);

        if (!$followSymlinks && is_link($oldPath)) {
            // PHP link() follows symlinks. For no-follow semantics on symlinks,
            // use Python's os.link with follow_symlinks=False to hardlink the symlink itself.
            $escaped_old = escapeshellarg($oldPath);
            $escaped_new = escapeshellarg($newPath);
            $cmd = "python3 -c " . escapeshellarg(
                "import os; os.link({$escaped_old}, {$escaped_new}, follow_symlinks=False)"
            );
            $ret = null;
            @exec($cmd, $output, $ret);
            if ($ret !== 0) {
                if (!file_exists(dirname($newPath))) return $this->err(Errno::NOENT);
                return $this->err(Errno::IO);
            }
        } else {
            if (!@link($oldPath, $newPath)) {
                // Check for common errors
                if (!file_exists(dirname($newPath))) return $this->err(Errno::NOENT);
                return $this->err(Errno::IO);
            }
        }
        return $this->ok();
    }

    // ---- path_filestat_get ----

    private function pathFilestatGet(array $args): array
    {
        $dirfd    = $args[0]->value;
        $flags    = $args[1]->value; // lookupflags
        $pathPtr  = $this->addr($args[2]);
        $pathLen  = $args[3]->value;
        $statPtr  = $this->addr($args[4]);

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);

        $followSymlinks = ($flags & self::LOOKUPFLAGS_SYMLINK_FOLLOW) !== 0;

        if ($followSymlinks) {
            $stat = @stat($absPath);
        } else {
            $stat = @lstat($absPath);
        }

        if ($stat === false) {
            return $this->err(Errno::NOENT);
        }

        $filetype = $this->filetypeFromPath($absPath, $followSymlinks);
        $this->writeFilestat($statPtr, $stat, $filetype, $absPath, $followSymlinks);
        return $this->ok();
    }

    // ---- path_filestat_set_times ----

    private function pathFilestatSetTimes(array $args): array
    {
        $dirfd    = $args[0]->value;
        $flags    = $args[1]->value; // lookupflags
        $pathPtr  = $this->addr($args[2]);
        $pathLen  = $args[3]->value;
        $atim     = $args[4]->value; // i64
        $mtim     = $args[5]->value; // i64
        $fstflags = $args[6]->value;

        if (!isset($this->preopens[$dirfd])) return $this->err(Errno::BADF);

        // Validate flags
        if (($fstflags & self::FSTFLAGS_ATIM) && ($fstflags & self::FSTFLAGS_ATIM_NOW)) {
            return $this->err(Errno::INVAL);
        }
        if (($fstflags & self::FSTFLAGS_MTIM) && ($fstflags & self::FSTFLAGS_MTIM_NOW)) {
            return $this->err(Errno::INVAL);
        }

        $relPath = substr($this->mem()->rawBytes(), $pathPtr, $pathLen);
        $absPath = $this->resolvePath($dirfd, $relPath);
        if ($absPath === null) return $this->err(Errno::BADF);
        if (!file_exists($absPath)) return $this->err(Errno::NOENT);

        $followSymlinks = ($flags & self::LOOKUPFLAGS_SYMLINK_FOLLOW) !== 0;

        // Get current timestamps in nanoseconds
        $nsData = $this->statNs($absPath);
        if ($nsData !== null) {
            $curAtimeNs = $nsData['atime_ns'];
            $curMtimeNs = $nsData['mtime_ns'];
        } else {
            $currentStat = @stat($absPath);
            $curAtimeNs = ($currentStat ? $currentStat['atime'] : time()) * 1_000_000_000;
            $curMtimeNs = ($currentStat ? $currentStat['mtime'] : time()) * 1_000_000_000;
        }

        $nowNs = (int)(microtime(true) * 1_000_000_000);
        $newAtimeNs = $curAtimeNs;
        $newMtimeNs = $curMtimeNs;

        if ($fstflags & self::FSTFLAGS_ATIM_NOW) {
            $newAtimeNs = $nowNs;
        } elseif ($fstflags & self::FSTFLAGS_ATIM) {
            $newAtimeNs = $atim;
        }

        if ($fstflags & self::FSTFLAGS_MTIM_NOW) {
            $newMtimeNs = $nowNs;
        } elseif ($fstflags & self::FSTFLAGS_MTIM) {
            $newMtimeNs = $mtim;
        }

        $this->utimeNs($absPath, $newAtimeNs, $newMtimeNs, $followSymlinks);
        return $this->ok();
    }

    // ---- poll_oneoff ----

    private function pollOneoff(array $args): array
    {
        $inPtr    = $this->addr($args[0]);
        $outPtr   = $this->addr($args[1]);
        $nsubsc   = $args[2]->value;
        $nevtsPtr = $this->addr($args[3]);

        $mem = $this->mem();

        // subscription struct layout (48 bytes):
        //   0: userdata (u64)
        //   8: u.tag (u8) — 0=clock, 1=fd_read, 2=fd_write
        //  16: u.u (tagged union, 32 bytes)
        //     clock: id(u32@16) + pad + timeout(u64@24) + precision(u64@32) + flags(u16@40)
        //     fd_read/write: file_descriptor(u32@16)

        // event struct layout (32 bytes):
        //   0: userdata (u64)
        //   8: error (u16)
        //  10: type (u8)
        //  16: fd_readwrite union (16 bytes) — nbytes(u64@16) + flags(u16@24)

        // Parse all subscriptions
        $clockSubs = [];
        $fdSubs = [];
        for ($i = 0; $i < $nsubsc; $i++) {
            $subBase  = $inPtr + $i * 48;
            $userdata = $mem->loadI64($subBase);
            $type     = $mem->loadI8u($subBase + 8);
            if ($type === 0) {
                // Parse clock subscription: extract timeout
                $timeoutNs = $mem->loadI64($subBase + 24);
                $clockSubs[] = ['userdata' => $userdata, 'type' => $type, 'timeout_ns' => $timeoutNs];
            } else {
                $fd = $mem->loadU32($subBase + 16);
                $fdSubs[] = ['userdata' => $userdata, 'type' => $type, 'fd' => $fd];
            }
        }

        $nevts = 0;

        // Separate FD subs into immediately-ready and needs-poll categories
        $readyFdSubs = [];
        $pollReadFds = [];   // fd_read subs that need stream_select
        $pollReadResources = []; // corresponding resources

        foreach ($fdSubs as $sub) {
            if ($sub['type'] === 2) {
                // fd_write: stdout/stderr/files are always writable
                $readyFdSubs[] = $sub;
            } elseif ($sub['type'] === 1) {
                $fd = $sub['fd'];
                $resource = $this->fdResource($fd);
                if ($resource === null) {
                    continue; // Invalid fd, skip
                }
                if (stream_isatty($resource)) {
                    // TTY stdin: always report as ready (fread will block for input)
                    $readyFdSubs[] = $sub;
                } elseif ($fd !== 0) {
                    // Regular files are always readable (may return EOF, that's fine)
                    $readyFdSubs[] = $sub;
                } else {
                    // Non-TTY stdin: needs actual polling
                    $pollReadFds[] = $sub;
                    $pollReadResources[$fd] = $resource;
                }
            }
        }

        // If we already have fd_write events ready, check if fd_read resources
        // are also immediately available (non-blocking check)
        if (count($readyFdSubs) > 0 && count($pollReadResources) > 0) {
            $read = array_values($pollReadResources);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 0);
            if ($changed !== false && $changed > 0) {
                foreach ($pollReadFds as $sub) {
                    $res = $pollReadResources[$sub['fd']] ?? null;
                    if ($res !== null && in_array($res, $read, true)) {
                        $readyFdSubs[] = $sub;
                    }
                }
                $pollReadFds = [];
                $pollReadResources = [];
            }
        }

        // Write ready fd events
        foreach ($readyFdSubs as $sub) {
            $evtBase = $outPtr + $nevts * 32;
            for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
            $mem->storeI64($evtBase, $sub['userdata']);
            $mem->storeI8($evtBase + 10, $sub['type'] & 0xFF);
            $nevts++;
        }

        // If there are still fd_read subs that need polling (e.g. stdin)
        // and we don't have any ready events yet, do a blocking poll
        if ($nevts === 0 && count($pollReadResources) > 0) {
            // Determine timeout from clock subscriptions
            $timeoutSec = null;
            $timeoutUsec = 0;
            if (count($clockSubs) > 0) {
                // Use the smallest clock timeout
                $minTimeoutNs = PHP_INT_MAX;
                foreach ($clockSubs as $cs) {
                    if ($cs['timeout_ns'] < $minTimeoutNs) {
                        $minTimeoutNs = $cs['timeout_ns'];
                    }
                }
                $timeoutSec = (int)($minTimeoutNs / 1_000_000_000);
                $timeoutUsec = (int)(($minTimeoutNs % 1_000_000_000) / 1_000);
            }

            $read = array_values($pollReadResources);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($changed !== false && $changed > 0) {
                // Some fds are ready
                foreach ($pollReadFds as $sub) {
                    $res = $pollReadResources[$sub['fd']] ?? null;
                    if ($res !== null && in_array($res, $read, true)) {
                        $evtBase = $outPtr + $nevts * 32;
                        for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
                        $mem->storeI64($evtBase, $sub['userdata']);
                        $mem->storeI8($evtBase + 10, $sub['type'] & 0xFF);
                        $nevts++;
                    }
                }
            } else {
                // Timeout expired — fire clock events
                foreach ($clockSubs as $sub) {
                    $evtBase = $outPtr + $nevts * 32;
                    for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
                    $mem->storeI64($evtBase, $sub['userdata']);
                    $mem->storeI8($evtBase + 10, 0); // clock type
                    $nevts++;
                }
            }
        } elseif ($nevts === 0 && count($clockSubs) > 0) {
            // No fd subscriptions at all, only clock — fire clock events
            foreach ($clockSubs as $sub) {
                $evtBase = $outPtr + $nevts * 32;
                for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
                $mem->storeI64($evtBase, $sub['userdata']);
                $mem->storeI8($evtBase + 10, 0); // clock type
                $nevts++;
            }
        }

        // Ensure at least one event
        if ($nevts === 0) {
            if (count($clockSubs) > 0) {
                $sub = $clockSubs[0];
                $evtBase = $outPtr;
                for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
                $mem->storeI64($evtBase, $sub['userdata']);
                $mem->storeI8($evtBase + 10, 0);
                $nevts = 1;
            } elseif (count($fdSubs) > 0) {
                // Last resort: report all fd subs as ready
                foreach ($fdSubs as $sub) {
                    $evtBase = $outPtr + $nevts * 32;
                    for ($j = 0; $j < 32; $j++) $mem->storeI8($evtBase + $j, 0);
                    $mem->storeI64($evtBase, $sub['userdata']);
                    $mem->storeI8($evtBase + 10, $sub['type'] & 0xFF);
                    $nevts++;
                }
            }
        }

        $mem->storeI32($nevtsPtr, $nevts);
        return $this->ok();
    }

    // ---- args_sizes_get ----

    private function argsSizesGet(array $args): array
    {
        $argcPtr        = $this->addr($args[0]);
        $argvBufSizePtr = $this->addr($args[1]);

        $mem     = $this->mem();
        $argc    = count($this->args);
        $bufSize = (int)array_sum(array_map(fn($a) => strlen($a) + 1, $this->args));

        $mem->storeI32($argcPtr, $argc);
        $mem->storeI32($argvBufSizePtr, $bufSize);
        return $this->ok();
    }

    private function argsSizesGetRaw(array $args, int $base, int $count): int
    {
        $argcPtr        = self::rawAddr($args, $base, 0);
        $argvBufSizePtr = self::rawAddr($args, $base, 1);

        $mem     = $this->mem();
        $argc    = count($this->args);
        $bufSize = (int)array_sum(array_map(fn($a) => strlen($a) + 1, $this->args));

        $mem->storeI32($argcPtr, $argc);
        $mem->storeI32($argvBufSizePtr, $bufSize);
        return $this->okRaw();
    }

    // ---- args_get ----

    private function argsGet(array $args): array
    {
        $argvPtr    = $this->addr($args[0]);
        $argvBufPtr = $this->addr($args[1]);

        $mem       = $this->mem();
        $bufCursor = $argvBufPtr;

        foreach ($this->args as $i => $arg) {
            $mem->storeI32($argvPtr + $i * 4, $bufCursor);
            $mem->init($bufCursor, $arg . "\0");
            $bufCursor += strlen($arg) + 1;
        }

        return $this->ok();
    }

    private function argsGetRaw(array $args, int $base, int $count): int
    {
        $argvPtr    = self::rawAddr($args, $base, 0);
        $argvBufPtr = self::rawAddr($args, $base, 1);

        $mem       = $this->mem();
        $bufCursor = $argvBufPtr;

        foreach ($this->args as $i => $arg) {
            $mem->storeI32($argvPtr + $i * 4, $bufCursor);
            $mem->init($bufCursor, $arg . "\0");
            $bufCursor += strlen($arg) + 1;
        }

        return $this->okRaw();
    }

    // ---- environ_sizes_get ----

    private function environSizesGet(array $args): array
    {
        $countPtr   = $this->addr($args[0]);
        $bufSizePtr = $this->addr($args[1]);

        $mem     = $this->mem();
        $count   = count($this->env);
        $bufSize = (int)array_sum(
            array_map(fn($k, $v) => strlen($k) + 1 + strlen($v) + 1, array_keys($this->env), $this->env)
        );

        $mem->storeI32($countPtr, $count);
        $mem->storeI32($bufSizePtr, $bufSize);
        return $this->ok();
    }

    private function environSizesGetRaw(array $args, int $base, int $count): int
    {
        $countPtr   = self::rawAddr($args, $base, 0);
        $bufSizePtr = self::rawAddr($args, $base, 1);

        $mem     = $this->mem();
        $countEnv   = count($this->env);
        $bufSize = (int)array_sum(
            array_map(fn($k, $v) => strlen($k) + 1 + strlen($v) + 1, array_keys($this->env), $this->env)
        );

        $mem->storeI32($countPtr, $countEnv);
        $mem->storeI32($bufSizePtr, $bufSize);
        return $this->okRaw();
    }

    // ---- environ_get ----

    private function environGet(array $args): array
    {
        $environPtr    = $this->addr($args[0]);
        $environBufPtr = $this->addr($args[1]);

        $mem       = $this->mem();
        $bufCursor = $environBufPtr;
        $i         = 0;

        foreach ($this->env as $key => $value) {
            $entry = "$key=$value\0";
            $mem->storeI32($environPtr + $i * 4, $bufCursor);
            $mem->init($bufCursor, $entry);
            $bufCursor += strlen($entry);
            $i++;
        }

        return $this->ok();
    }

    private function environGetRaw(array $args, int $base, int $count): int
    {
        $environPtr    = self::rawAddr($args, $base, 0);
        $environBufPtr = self::rawAddr($args, $base, 1);

        $mem       = $this->mem();
        $bufCursor = $environBufPtr;
        $i         = 0;

        foreach ($this->env as $key => $value) {
            $entry = "$key=$value\0";
            $mem->storeI32($environPtr + $i * 4, $bufCursor);
            $mem->init($bufCursor, $entry);
            $bufCursor += strlen($entry);
            $i++;
        }

        return $this->okRaw();
    }

    // ---- clock_time_get ----

    private function clockTimeGet(array $args): array
    {
        $clockId = $args[0]->value;
        // $precision = $args[1]->value; // i64, ignored
        $timePtr = $this->addr($args[2]);

        $ns = match ($clockId) {
            0       => (int)(microtime(true) * 1_000_000_000), // realtime
            default => hrtime(true),                            // monotonic
        };
        $this->mem()->storeI64($timePtr, $ns);
        return $this->ok();
    }

    private function clockTimeGetRaw(array $args, int $base, int $count): int
    {
        $clockId = (int)($args[$base] ?? 0);
        $timePtr = self::rawAddr($args, $base, 2);

        $ns = match ($clockId) {
            0       => (int)(microtime(true) * 1_000_000_000),
            default => hrtime(true),
        };
        $this->mem()->storeI64($timePtr, $ns);
        return $this->okRaw();
    }

    // ---- clock_res_get ----

    private function clockResGet(array $args): array
    {
        $clockId   = $args[0]->value;
        $resoPtr   = $this->addr($args[1]);

        // Report 1 microsecond resolution
        $this->mem()->storeI64($resoPtr, 1000);
        return $this->ok();
    }

    private function clockResGetRaw(array $args, int $base, int $count): int
    {
        $resoPtr   = self::rawAddr($args, $base, 1);
        $this->mem()->storeI64($resoPtr, 1000);
        return $this->okRaw();
    }

    // ---- random_get ----

    private function randomGet(array $args): array
    {
        $bufPtr = $this->addr($args[0]);
        $bufLen = $args[1]->value;

        $this->mem()->init($bufPtr, random_bytes($bufLen));
        return $this->ok();
    }

    private function randomGetRaw(array $args, int $base, int $count): int
    {
        $bufPtr = self::rawAddr($args, $base, 0);
        $bufLen = (int)($args[$base + 1] ?? 0);

        $this->mem()->init($bufPtr, random_bytes($bufLen));
        return $this->okRaw();
    }

    // ---- proc_exit ----

    private function procExit(array $args): array
    {
        throw new WasiExitException($args[0]->value & 0xFF);
    }

    private function procExitRaw(array $args, int $base, int $count): never
    {
        throw new WasiExitException(((int)($args[$base] ?? 0)) & 0xFF);
    }
}
