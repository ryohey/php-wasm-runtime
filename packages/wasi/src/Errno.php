<?php

declare(strict_types=1);

namespace WasmRuntime\Wasi;

/**
 * WASI errno values (wasi_snapshot_preview1).
 * https://github.com/WebAssembly/WASI/blob/main/legacy/preview1/docs.md#-errno-variant
 */
final class Errno
{
    public const SUCCESS        = 0;
    public const TOOBIG         = 1;
    public const ACCES          = 2;
    public const ADDRINUSE      = 3;
    public const ADDRNOTAVAIL   = 4;
    public const AFNOSUPPORT    = 5;
    public const AGAIN          = 6;
    public const ALREADY        = 7;
    public const BADF           = 8;
    public const BADMSG         = 9;
    public const BUSY           = 10;
    public const CANCELED       = 11;
    public const CHILD          = 12;
    public const CONNABORTED    = 13;
    public const CONNREFUSED    = 14;
    public const CONNRESET      = 15;
    public const DEADLK         = 16;
    public const DESTADDRREQ    = 17;
    public const DOM            = 18;
    public const DQUOT          = 19;
    public const EXIST          = 20;
    public const FAULT          = 21;
    public const FBIG           = 22;
    public const HOSTUNREACH    = 23;
    public const IDRM           = 24;
    public const ILSEQ          = 25;
    public const INPROGRESS     = 26;
    public const INTR           = 27;
    public const INVAL          = 28;
    public const IO             = 29;
    public const ISCONN         = 30;
    public const ISDIR          = 31;
    public const LOOP           = 32;
    public const MFILE          = 33;
    public const MLINK          = 34;
    public const MSGSIZE        = 35;
    public const MULTIHOP       = 36;
    public const NAMETOOLONG    = 37;
    public const NETDOWN        = 38;
    public const NETRESET       = 39;
    public const NETUNREACH     = 40;
    public const NFILE          = 41;
    public const NOBUFS         = 42;
    public const NODEV          = 43;
    public const NOENT          = 44;
    public const NOEXEC         = 45;
    public const NOLCK          = 46;
    public const NOLINK         = 47;
    public const NOMEM          = 48;
    public const NOMSG          = 49;
    public const NOPROTOOPT     = 50;
    public const NOSPC          = 51;
    public const NOSYS          = 52;
    public const NOTCONN        = 53;
    public const NOTDIR         = 54;
    public const NOTEMPTY       = 55;
    public const NOTRECOVERABLE = 56;
    public const NOTSOCK        = 57;
    public const NOTSUP         = 58;
    public const NOTTY          = 59;
    public const NXIO           = 60;
    public const OVERFLOW       = 61;
    public const OWNERDEAD      = 62;
    public const PERM           = 63;
    public const PIPE           = 64;
    public const PROTO          = 65;
    public const PROTONOSUPPORT = 66;
    public const PROTOTYPE      = 67;
    public const RANGE          = 68;
    public const ROFS           = 69;
    public const SPIPE          = 70;
    public const SRCH           = 71;
    public const STALE          = 72;
    public const TIMEDOUT       = 73;
    public const TXTBSY         = 74;
    public const XDEV           = 75;
    public const NOTCAPABLE     = 76;
}
