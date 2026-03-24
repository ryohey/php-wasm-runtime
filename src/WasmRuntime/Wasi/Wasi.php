<?php

declare(strict_types=1);

namespace WasmRuntime\Wasi;

use WasmRuntime\Instance;
use WasmRuntime\Memory;
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

    /** @var array<int, resource> Open file descriptors (fd >= 3 are user-opened) */
    private array $openFds = [];

    /** @var array<int, string> Pre-opened directory fds => path */
    private array $preopens = [];

    private int $nextFd = 3; // 0=stdin, 1=stdout, 2=stderr are fixed

    /**
     * @param string[]            $args        argv (index 0 is the program name)
     * @param array<string,string> $env        environment variables (key => value)
     * @param string[]            $preopenDirs directories to pre-open for path access
     */
    public function __construct(
        private readonly array $args = [],
        private readonly array $env = [],
        array $preopenDirs = [],
    ) {
        foreach ($preopenDirs as $dir) {
            $this->preopens[$this->nextFd++] = $dir;
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
                'args_get'            => fn(array $a) => $this->argsGet($a),
                'args_sizes_get'      => fn(array $a) => $this->argsSizesGet($a),
                'environ_get'         => fn(array $a) => $this->environGet($a),
                'environ_sizes_get'   => fn(array $a) => $this->environSizesGet($a),
                'clock_time_get'      => fn(array $a) => $this->clockTimeGet($a),
                'fd_close'            => fn(array $a) => $this->fdClose($a),
                'fd_fdstat_get'       => fn(array $a) => $this->fdFdstatGet($a),
                'fd_prestat_get'      => fn(array $a) => $this->fdPrestatGet($a),
                'fd_prestat_dir_name' => fn(array $a) => $this->fdPrestatDirName($a),
                'fd_read'             => fn(array $a) => $this->fdRead($a),
                'fd_seek'             => fn(array $a) => $this->fdSeek($a),
                'fd_write'            => fn(array $a) => $this->fdWrite($a),
                'path_open'           => fn(array $a) => $this->pathOpen($a),
                'proc_exit'           => fn(array $a) => $this->procExit($a),
                'random_get'          => fn(array $a) => $this->randomGet($a),
            ],
        ];
    }

    // ---- private helpers ----

    private function mem(): Memory
    {
        return $this->instance->memories[0];
    }

    /** Treat a WasmValue i32 as an unsigned 32-bit address */
    private function addr(WasmValue $v): int
    {
        return $v->value & 0xFFFFFFFF;
    }

    private function ok(): array
    {
        return [WasmValue::i32(Errno::SUCCESS)];
    }

    private function err(int $errno): array
    {
        return [WasmValue::i32($errno)];
    }

    // ---- WASI syscall implementations ----

    /**
     * fd_write(fd, iovs, iovs_len, nwritten) -> errno
     *
     * Each iovec is 8 bytes: { buf: u32, buf_len: u32 }.
     */
    private function fdWrite(array $args): array
    {
        $fd          = $args[0]->value;
        $iovs        = $this->addr($args[1]);
        $iovsLen     = $args[2]->value;
        $nwrittenPtr = $this->addr($args[3]);

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

            match ($fd) {
                1 => fwrite(STDOUT, $data),
                2 => fwrite(STDERR, $data),
                default => isset($this->openFds[$fd]) ? fwrite($this->openFds[$fd], $data) : null,
            };

            $totalWritten += $bufLen;
        }

        $mem->storeI32($nwrittenPtr, $totalWritten);
        return $this->ok();
    }

    /**
     * fd_read(fd, iovs, iovs_len, nread) -> errno
     */
    private function fdRead(array $args): array
    {
        $fd       = $args[0]->value;
        $iovs     = $this->addr($args[1]);
        $iovsLen  = $args[2]->value;
        $nreadPtr = $this->addr($args[3]);

        $mem       = $this->mem();
        $totalRead = 0;

        $resource = match ($fd) {
            0       => STDIN,
            default => $this->openFds[$fd] ?? null,
        };

        if ($resource === null) {
            return $this->err(Errno::BADF);
        }

        for ($i = 0; $i < $iovsLen; $i++) {
            $base   = $iovs + $i * 8;
            $bufPtr = $mem->loadU32($base);
            $bufLen = $mem->loadU32($base + 4);

            if ($bufLen === 0) {
                continue;
            }

            $data = fread($resource, $bufLen);
            if ($data === false) {
                break;
            }

            $readLen = strlen($data);
            $mem->init($bufPtr, $data);
            $totalRead += $readLen;

            if ($readLen < $bufLen) {
                break; // EOF
            }
        }

        $mem->storeI32($nreadPtr, $totalRead);
        return $this->ok();
    }

    /**
     * fd_close(fd) -> errno
     */
    private function fdClose(array $args): array
    {
        $fd = $args[0]->value;

        if ($fd <= 2) {
            return $this->err(Errno::NOTSUP);
        }

        if (!isset($this->openFds[$fd])) {
            return $this->err(Errno::BADF);
        }

        fclose($this->openFds[$fd]);
        unset($this->openFds[$fd]);
        return $this->ok();
    }

    /**
     * fd_seek(fd, offset, whence, newoffset) -> errno
     * offset: i64, whence: i32, newoffset: pointer to i64
     */
    private function fdSeek(array $args): array
    {
        $fd           = $args[0]->value;
        $offset       = $args[1]->value; // i64
        $whence       = $args[2]->value;
        $newoffsetPtr = $this->addr($args[3]);

        if (!isset($this->openFds[$fd])) {
            return $this->err(Errno::BADF);
        }

        $seekWhence = match ($whence) {
            0 => SEEK_SET,
            1 => SEEK_CUR,
            2 => SEEK_END,
            default => null,
        };

        if ($seekWhence === null) {
            return $this->err(Errno::INVAL);
        }

        if (fseek($this->openFds[$fd], $offset, $seekWhence) !== 0) {
            return $this->err(Errno::IO);
        }

        $pos = ftell($this->openFds[$fd]);
        $this->mem()->storeI64($newoffsetPtr, (int)$pos);
        return $this->ok();
    }

    /**
     * fd_fdstat_get(fd, stat) -> errno
     *
     * Writes a 24-byte fdstat struct:
     *   offset  0: fs_filetype  (u8)
     *   offset  1: fs_flags     (u16)
     *   offset  3: padding      (5 bytes)
     *   offset  8: fs_rights_base        (u64)
     *   offset 16: fs_rights_inheriting  (u64)
     */
    private function fdFdstatGet(array $args): array
    {
        $fd      = $args[0]->value;
        $statPtr = $this->addr($args[1]);

        $isStd     = $fd <= 2;
        $isPreopen = isset($this->preopens[$fd]);
        $isOpen    = isset($this->openFds[$fd]);

        if (!$isStd && !$isPreopen && !$isOpen) {
            return $this->err(Errno::BADF);
        }

        $mem = $this->mem();

        // fs_filetype: 2=character_device, 3=directory, 4=regular_file
        $fileType = match (true) {
            $isStd     => 2,
            $isPreopen => 3,
            default    => 4,
        };

        $mem->storeI8($statPtr, $fileType);
        $mem->storeI8($statPtr + 1, 0); // fs_flags low
        $mem->storeI8($statPtr + 2, 0); // fs_flags high
        for ($j = 3; $j < 8; $j++) {
            $mem->storeI8($statPtr + $j, 0); // padding
        }
        $mem->storeI64($statPtr + 8, -1);  // fs_rights_base (all rights)
        $mem->storeI64($statPtr + 16, -1); // fs_rights_inheriting (all rights)

        return $this->ok();
    }

    /**
     * fd_prestat_get(fd, prestat) -> errno
     *
     * prestat struct (8 bytes):
     *   offset 0: tag       (u8, 0=dir)
     *   offset 4: name_len  (u32)
     */
    private function fdPrestatGet(array $args): array
    {
        $fd         = $args[0]->value;
        $prestatPtr = $this->addr($args[1]);

        if (!isset($this->preopens[$fd])) {
            return $this->err(Errno::BADF);
        }

        $mem = $this->mem();
        $mem->storeI8($prestatPtr, 0); // tag = dir
        $mem->storeI32($prestatPtr + 4, strlen($this->preopens[$fd]));

        return $this->ok();
    }

    /**
     * fd_prestat_dir_name(fd, path, path_len) -> errno
     */
    private function fdPrestatDirName(array $args): array
    {
        $fd      = $args[0]->value;
        $pathPtr = $this->addr($args[1]);
        $pathLen = $args[2]->value;

        if (!isset($this->preopens[$fd])) {
            return $this->err(Errno::BADF);
        }

        $this->mem()->init($pathPtr, substr($this->preopens[$fd], 0, $pathLen));
        return $this->ok();
    }

    /**
     * path_open(dirfd, dirflags, path_ptr, path_len, oflags,
     *           fs_rights_base, fs_rights_inheriting, fdflags, fd_ptr) -> errno
     */
    private function pathOpen(array $args): array
    {
        $dirfd   = $args[0]->value;
        // $dirflags            = $args[1]->value; // lookupflags (SYMLINK_FOLLOW), unused
        $pathPtr = $this->addr($args[2]);
        $pathLen = $args[3]->value;
        $oflags  = $args[4]->value;
        // $fsRightsBase        = $args[5]->value; // unused
        // $fsRightsInheriting  = $args[6]->value; // unused
        // $fdflags             = $args[7]->value; // unused
        $fdPtr   = $this->addr($args[8]);

        if (!isset($this->preopens[$dirfd])) {
            return $this->err(Errno::BADF);
        }

        $mem     = $this->mem();
        $relPath = substr($mem->rawBytes(), $pathPtr, $pathLen);
        $dirPath = rtrim($this->preopens[$dirfd], '/');
        $absPath = $dirPath . '/' . $relPath;

        // oflags bits: 0x01=creat, 0x02=directory, 0x04=excl, 0x08=trunc
        $createDir = ($oflags & 0x02) !== 0;
        $create    = ($oflags & 0x01) !== 0;
        $exclusive = ($oflags & 0x04) !== 0;
        $truncate  = ($oflags & 0x08) !== 0;

        if ($createDir) {
            if (!is_dir($absPath) && !mkdir($absPath, 0777, true)) {
                return $this->err(Errno::IO);
            }
            $fd = $this->nextFd++;
            $this->preopens[$fd] = $absPath;
            $mem->storeI32($fdPtr, $fd);
            return $this->ok();
        }

        $mode = match (true) {
            $create && $truncate => 'w+b',
            $create && $exclusive => 'xb',
            $create  => 'a+b',
            $truncate => 'w+b',
            default  => 'rb',
        };

        $handle = @fopen($absPath, $mode);
        if ($handle === false) {
            return $this->err(file_exists($absPath) ? Errno::ACCES : Errno::NOENT);
        }

        $fd = $this->nextFd++;
        $this->openFds[$fd] = $handle;
        $mem->storeI32($fdPtr, $fd);
        return $this->ok();
    }

    /**
     * args_sizes_get(argc_ptr, argv_buf_size_ptr) -> errno
     */
    private function argsSizesGet(array $args): array
    {
        $argcPtr       = $this->addr($args[0]);
        $argvBufSizePtr = $this->addr($args[1]);

        $mem     = $this->mem();
        $argc    = count($this->args);
        $bufSize = (int)array_sum(array_map(fn($a) => strlen($a) + 1, $this->args));

        $mem->storeI32($argcPtr, $argc);
        $mem->storeI32($argvBufSizePtr, $bufSize);
        return $this->ok();
    }

    /**
     * args_get(argv_ptr, argv_buf_ptr) -> errno
     *
     * argv_ptr points to an array of u32 pointers (one per arg).
     * argv_buf_ptr is the buffer where null-terminated strings are written.
     */
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

    /**
     * environ_sizes_get(environ_count_ptr, environ_buf_size_ptr) -> errno
     */
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

    /**
     * environ_get(environ_ptr, environ_buf_ptr) -> errno
     *
     * environ_ptr: array of u32 pointers to "KEY=VALUE\0" strings.
     */
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

    /**
     * clock_time_get(clock_id, precision, time_ptr) -> errno
     * clock_id: 0=realtime, 1=monotonic
     * time_ptr: pointer to write u64 nanoseconds
     */
    private function clockTimeGet(array $args): array
    {
        // $clockId   = $args[0]->value; // 0=realtime, 1=monotonic
        // $precision = $args[1]->value; // i64, ignored
        $timePtr = $this->addr($args[2]);

        // hrtime(true) returns monotonic nanoseconds as int (PHP 7.3+)
        $ns = hrtime(true);
        $this->mem()->storeI64($timePtr, $ns);
        return $this->ok();
    }

    /**
     * random_get(buf_ptr, buf_len) -> errno
     */
    private function randomGet(array $args): array
    {
        $bufPtr = $this->addr($args[0]);
        $bufLen = $args[1]->value;

        $this->mem()->init($bufPtr, random_bytes($bufLen));
        return $this->ok();
    }

    /**
     * proc_exit(exit_code)
     * Throws WasiExitException; catch it in the host to read the exit code.
     */
    private function procExit(array $args): array
    {
        throw new WasiExitException($args[0]->value & 0xFF);
    }
}
