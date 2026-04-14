<?php

declare(strict_types=1);

namespace WasmRuntime;

/**
 * Integer opcode constants for the flat bytecode stream.
 *
 * Decoder emits these into a flat array: Op::XXX, immediate1, immediate2, ...
 * Executor dispatches on the integer directly (no string comparison).
 */
final class Op
{
    // ---- Control ----
    public const UNREACHABLE = 1;
    public const NOP = 2;
    public const BLOCK = 3;        // blockType, endIp
    public const LOOP = 4;         // blockType, contIp, endIp
    public const IF_ = 5;          // blockType, elseIp, endIp
    public const ELSE_ = 6;        // endIp
    public const END = 7;
    public const BR = 8;           // depth
    public const BR_IF = 9;        // depth
    public const BR_TABLE = 10;    // count, label0..labelN, default
    public const RETURN_ = 11;
    public const CALL = 12;        // funcIdx
    public const CALL_INDIRECT = 13; // typeIdx, tableIdx
    public const RETURN_CALL = 14; // funcIdx
    public const RETURN_CALL_INDIRECT = 15; // typeIdx, tableIdx

    // ---- Parametric ----
    public const DROP = 20;
    public const SELECT = 21;

    // ---- Variable ----
    public const LOCAL_GET = 30;   // localIdx
    public const LOCAL_SET = 31;   // localIdx
    public const LOCAL_TEE = 32;   // localIdx
    public const GLOBAL_GET = 33;  // globalIdx
    public const GLOBAL_SET = 34;  // globalIdx

    // ---- Table ----
    public const TABLE_GET = 40;   // tableIdx
    public const TABLE_SET = 41;   // tableIdx
    public const TABLE_SIZE = 42;  // tableIdx
    public const TABLE_GROW = 43;  // tableIdx
    public const TABLE_FILL = 44;  // tableIdx
    public const TABLE_COPY = 45;  // dstTableIdx, srcTableIdx
    public const TABLE_INIT = 46;  // tableIdx, elemIdx
    public const ELEM_DROP = 47;   // elemIdx

    // ---- Memory load ----
    public const I32_LOAD = 50;       // offset
    public const I64_LOAD = 51;       // offset
    public const F32_LOAD = 52;       // offset
    public const F64_LOAD = 53;       // offset
    public const I32_LOAD8_S = 54;    // offset
    public const I32_LOAD8_U = 55;    // offset
    public const I32_LOAD16_S = 56;   // offset
    public const I32_LOAD16_U = 57;   // offset
    public const I64_LOAD8_S = 58;    // offset
    public const I64_LOAD8_U = 59;    // offset
    public const I64_LOAD16_S = 60;   // offset
    public const I64_LOAD16_U = 61;   // offset
    public const I64_LOAD32_S = 62;   // offset
    public const I64_LOAD32_U = 63;   // offset

    // ---- Memory store ----
    public const I32_STORE = 70;      // offset
    public const I64_STORE = 71;      // offset
    public const F32_STORE = 72;      // offset
    public const F64_STORE = 73;      // offset
    public const I32_STORE8 = 74;     // offset
    public const I32_STORE16 = 75;    // offset
    public const I64_STORE8 = 76;     // offset
    public const I64_STORE16 = 77;    // offset
    public const I64_STORE32 = 78;    // offset

    // ---- Memory management ----
    public const MEMORY_SIZE = 80;
    public const MEMORY_GROW = 81;
    public const MEMORY_FILL = 82;
    public const MEMORY_COPY = 83;
    public const MEMORY_INIT = 84;    // segIdx
    public const DATA_DROP = 85;      // segIdx

    // ---- Constants ----
    public const I32_CONST = 90;  // value
    public const I64_CONST = 91;  // value
    public const F32_CONST = 92;  // value
    public const F64_CONST = 93;  // value

    // ---- i32 comparison ----
    public const I32_EQZ = 100;
    public const I32_EQ = 101;
    public const I32_NE = 102;
    public const I32_LT_S = 103;
    public const I32_LT_U = 104;
    public const I32_GT_S = 105;
    public const I32_GT_U = 106;
    public const I32_LE_S = 107;
    public const I32_LE_U = 108;
    public const I32_GE_S = 109;
    public const I32_GE_U = 110;

    // ---- i64 comparison ----
    public const I64_EQZ = 111;
    public const I64_EQ = 112;
    public const I64_NE = 113;
    public const I64_LT_S = 114;
    public const I64_LT_U = 115;
    public const I64_GT_S = 116;
    public const I64_GT_U = 117;
    public const I64_LE_S = 118;
    public const I64_LE_U = 119;
    public const I64_GE_S = 120;
    public const I64_GE_U = 121;

    // ---- f32 comparison ----
    public const F32_EQ = 130;
    public const F32_NE = 131;
    public const F32_LT = 132;
    public const F32_GT = 133;
    public const F32_LE = 134;
    public const F32_GE = 135;

    // ---- f64 comparison ----
    public const F64_EQ = 140;
    public const F64_NE = 141;
    public const F64_LT = 142;
    public const F64_GT = 143;
    public const F64_LE = 144;
    public const F64_GE = 145;

    // ---- i32 arithmetic ----
    public const I32_CLZ = 150;
    public const I32_CTZ = 151;
    public const I32_POPCNT = 152;
    public const I32_ADD = 153;
    public const I32_SUB = 154;
    public const I32_MUL = 155;
    public const I32_DIV_S = 156;
    public const I32_DIV_U = 157;
    public const I32_REM_S = 158;
    public const I32_REM_U = 159;
    public const I32_AND = 160;
    public const I32_OR = 161;
    public const I32_XOR = 162;
    public const I32_SHL = 163;
    public const I32_SHR_S = 164;
    public const I32_SHR_U = 165;
    public const I32_ROTL = 166;
    public const I32_ROTR = 167;

    // ---- i64 arithmetic ----
    public const I64_CLZ = 170;
    public const I64_CTZ = 171;
    public const I64_POPCNT = 172;
    public const I64_ADD = 173;
    public const I64_SUB = 174;
    public const I64_MUL = 175;
    public const I64_DIV_S = 176;
    public const I64_DIV_U = 177;
    public const I64_REM_S = 178;
    public const I64_REM_U = 179;
    public const I64_AND = 180;
    public const I64_OR = 181;
    public const I64_XOR = 182;
    public const I64_SHL = 183;
    public const I64_SHR_S = 184;
    public const I64_SHR_U = 185;
    public const I64_ROTL = 186;
    public const I64_ROTR = 187;

    // ---- f32 arithmetic ----
    public const F32_ABS = 190;
    public const F32_NEG = 191;
    public const F32_CEIL = 192;
    public const F32_FLOOR = 193;
    public const F32_TRUNC = 194;
    public const F32_NEAREST = 195;
    public const F32_SQRT = 196;
    public const F32_ADD = 197;
    public const F32_SUB = 198;
    public const F32_MUL = 199;
    public const F32_DIV = 200;
    public const F32_MIN = 201;
    public const F32_MAX = 202;
    public const F32_COPYSIGN = 203;

    // ---- f64 arithmetic ----
    public const F64_ABS = 210;
    public const F64_NEG = 211;
    public const F64_CEIL = 212;
    public const F64_FLOOR = 213;
    public const F64_TRUNC = 214;
    public const F64_NEAREST = 215;
    public const F64_SQRT = 216;
    public const F64_ADD = 217;
    public const F64_SUB = 218;
    public const F64_MUL = 219;
    public const F64_DIV = 220;
    public const F64_MIN = 221;
    public const F64_MAX = 222;
    public const F64_COPYSIGN = 223;

    // ---- Conversions ----
    public const I32_WRAP_I64 = 230;
    public const I32_TRUNC_F32_S = 231;
    public const I32_TRUNC_F32_U = 232;
    public const I32_TRUNC_F64_S = 233;
    public const I32_TRUNC_F64_U = 234;
    public const I64_EXTEND_I32_S = 235;
    public const I64_EXTEND_I32_U = 236;
    public const I64_TRUNC_F32_S = 237;
    public const I64_TRUNC_F32_U = 238;
    public const I64_TRUNC_F64_S = 239;
    public const I64_TRUNC_F64_U = 240;
    public const F32_CONVERT_I32_S = 241;
    public const F32_CONVERT_I32_U = 242;
    public const F32_CONVERT_I64_S = 243;
    public const F32_CONVERT_I64_U = 244;
    public const F32_DEMOTE_F64 = 245;
    public const F64_CONVERT_I32_S = 246;
    public const F64_CONVERT_I32_U = 247;
    public const F64_CONVERT_I64_S = 248;
    public const F64_CONVERT_I64_U = 249;
    public const F64_PROMOTE_F32 = 250;

    // ---- Reinterpret ----
    public const I32_REINTERPRET_F32 = 260;
    public const I64_REINTERPRET_F64 = 261;
    public const F32_REINTERPRET_I32 = 262;
    public const F64_REINTERPRET_I64 = 263;

    // ---- Sign extension ----
    public const I32_EXTEND8_S = 270;
    public const I32_EXTEND16_S = 271;
    public const I64_EXTEND8_S = 272;
    public const I64_EXTEND16_S = 273;
    public const I64_EXTEND32_S = 274;

    // ---- References ----
    public const REF_NULL = 280;
    public const REF_IS_NULL = 281;
    public const REF_FUNC = 282;       // funcIdx
    public const REF_AS_NON_NULL = 283;

    // ---- Saturating truncation ----
    public const I32_TRUNC_SAT_F32_S = 290;
    public const I32_TRUNC_SAT_F32_U = 291;
    public const I32_TRUNC_SAT_F64_S = 292;
    public const I32_TRUNC_SAT_F64_U = 293;
    public const I64_TRUNC_SAT_F32_S = 294;
    public const I64_TRUNC_SAT_F32_U = 295;
    public const I64_TRUNC_SAT_F64_S = 296;
    public const I64_TRUNC_SAT_F64_U = 297;

    // ---- Super instructions (peephole-fused at decode time) ----
    // These combine 2-3 common instruction sequences into a single dispatch.
    // Immediates follow each super instruction in the flat code array.
    public const SB_LGET_ICONST_IADD   = 300; // local.get $x + i32.const $c + i32.add   → [local_idx, const_val]
    public const SB_LGET_I32LOAD       = 301; // local.get $x + i32.load $off            → [local_idx, mem_offset]
    public const SB_LGET_I32LOAD_LTEE  = 302; // local.get $x + i32.load $off + local.tee $y → [local_idx, mem_offset, tee_idx]
    public const SB_I32EQZ_BRIF        = 303; // i32.eqz + br_if $depth                  → [depth]
    public const SB_ICONST_IADD        = 304; // i32.const $c + i32.add                  → [const_val]
    public const SB_LGET_LGET          = 305; // local.get $x + local.get $y            → [local_idx_a, local_idx_b]
    public const SB_LGET_ICONST        = 306; // local.get $x + i32.const $c            → [local_idx, const_val]
    public const SB_LGET_I32WRAP_LTEE  = 307; // local.get $x + i32.wrap_i64 + local.tee $y → [local_idx, tee_idx]
    public const SB_I64LTU_BRIF        = 308; // i64.lt_u + br_if $depth                → [depth]
    public const SB_I32NE_BRIF         = 309; // i32.ne + br_if $depth                  → [depth]
    public const SB_I32GTS_BRIF        = 310; // i32.gt_s + br_if $depth                → [depth]
    public const SB_I32LTS_BRIF        = 311; // i32.lt_s + br_if $depth                → [depth]
    public const SB_I32EQ_BRIF         = 312; // i32.eq + br_if $depth                  → [depth]
    public const SB_I32GTU_BRIF        = 313; // i32.gt_u + br_if $depth                → [depth]
    public const SB_I64EQ_BRIF         = 314; // i64.eq + br_if $depth                  → [depth]

    // Loop-continue fast branches: emitted when br_if/compare+br_if targets a depth=0 0-param LOOP
    // within the current function. The absolute contIp is pre-encoded; no label stack lookup,
    // lsBase check, or $sp update is required at runtime (all are no-ops for this case).
    public const SB_BRIF_LOOP        = 315; // br_if → 0-param loop            → [contIp]
    public const SB_I32EQZ_BRIF_LOOP = 316; // i32.eqz + br_if → 0-param loop  → [contIp]
    public const SB_I64LTU_BRIF_LOOP = 317; // i64.lt_u + br_if → 0-param loop → [contIp]
    public const SB_I32NE_BRIF_LOOP  = 318; // i32.ne + br_if → 0-param loop   → [contIp]
    public const SB_I32GTS_BRIF_LOOP = 319; // i32.gt_s + br_if → 0-param loop → [contIp]
    public const SB_I32LTS_BRIF_LOOP = 320; // i32.lt_s + br_if → 0-param loop → [contIp]
    public const SB_I32EQ_BRIF_LOOP  = 321; // i32.eq + br_if → 0-param loop   → [contIp]
    public const SB_I32GTU_BRIF_LOOP = 322; // i32.gt_u + br_if → 0-param loop → [contIp]
    public const SB_I64EQ_BRIF_LOOP  = 323; // i64.eq + br_if → 0-param loop   → [contIp]

    // PC-fetch / pointer-load patterns (QuickJS bytecode dispatch hot path)
    public const SB_LGET_I32LOAD8U      = 324; // local.get $x + i32.load8_u $off            → [local_idx, mem_offset]
    public const SB_LGET_I32LOAD8U_LTEE = 325; // local.get $x + i32.load8_u $off + local.tee $y → [local_idx, mem_offset, tee_idx]

    // PC-increment patterns: local.get + i32.const + i32.add → local.set/tee
    public const SB_LGET_ICONST_IADD_LSET = 326; // local.get $x + i32.const $c + i32.add + local.set $y → [local_idx, const_val, set_idx]
    public const SB_LGET_ICONST_IADD_LTEE = 327; // local.get $x + i32.const $c + i32.add + local.tee $y → [local_idx, const_val, tee_idx]

    public const SB_I32LTU_BRIF     = 328; // i32.lt_u + br_if $depth                → [depth]
    public const SB_I32LES_BRIF     = 329; // i32.le_s + br_if $depth                → [depth]
    public const SB_I32LEU_BRIF     = 330; // i32.le_u + br_if $depth                → [depth]
    public const SB_I32GES_BRIF     = 331; // i32.ge_s + br_if $depth                → [depth]
    public const SB_I32GEU_BRIF     = 332; // i32.ge_u + br_if $depth                → [depth]
    public const SB_I64NE_BRIF      = 333; // i64.ne + br_if $depth                  → [depth]
    public const SB_I32LTU_BRIF_LOOP = 334; // i32.lt_u + br_if → 0-param loop      → [contIp]
    public const SB_I32LES_BRIF_LOOP = 335; // i32.le_s + br_if → 0-param loop      → [contIp]
    public const SB_I32LEU_BRIF_LOOP = 336; // i32.le_u + br_if → 0-param loop      → [contIp]
    public const SB_I32GES_BRIF_LOOP = 337; // i32.ge_s + br_if → 0-param loop      → [contIp]
    public const SB_I32GEU_BRIF_LOOP = 338; // i32.ge_u + br_if → 0-param loop      → [contIp]
    public const SB_I64NE_BRIF_LOOP  = 339; // i64.ne + br_if → 0-param loop        → [contIp]

    public const SB_BR_LOOP          = 340; // br 0 → 0-param loop (unconditional)  → [contIp]
    public const SB_BR_TABLE_VOID    = 341; // br_table where all targets are 0-result blocks → [cnt, d0..dN, default]
    public const SB_LGET_ICONST_IADD_LTEE_BRIF_LOOP = 342; // local.get+i32.const+i32.add+local.tee + br_if→loop  → [x,c,y,contIp]
    public const SB_LGET_ICONST_IADD_LTEE_I32LOAD   = 343; // local.get+i32.const+i32.add+local.tee + i32.load    → [x,c,y,off]
    public const SB_ICONST_LSET          = 344; // i32.const $c + local.set $y                                   → [c, y]
    public const SB_LGET_I32LOAD_LSET    = 345; // local.get $x + i32.load $off + local.set $y                   → [x, off, y]
    public const SB_LGET_LGET_I32STORE   = 346; // local.get $a + local.get $b + i32.store $off                  → [a, b, off]
    public const SB_ICONST_IADD_I32STORE = 347; // i32.const $c + i32.add + i32.store $off                       → [c, off]
    public const SB_I32LOAD_LTEE         = 348; // i32.load $off + local.tee $y                                   → [off, y]
    public const SB_I64CONST_LSET        = 349; // i64.const $c + local.set $y                                    → [c, y]
    public const SB_LGET_ICONST_I32GTS_BRIF      = 350; // local.get+i32.const+i32.gt_s+br_if           → [x, c, depth]
    public const SB_LGET_ICONST_I32GTS_BRIF_LOOP = 351; // local.get+i32.const+i32.gt_s+br_if→loop      → [x, c, contIp]
    public const SB_I64CONST_I64LTU_BRIF         = 352; // i64.const+i64.lt_u+br_if                     → [c, depth]
    public const SB_I64CONST_I64LTU_BRIF_LOOP    = 353; // i64.const+i64.lt_u+br_if→loop                → [c, contIp]
    public const SB_LGET_LGET_I32LOAD            = 354; // local.get $a + local.get $b + i32.load $off  → [a, b, off]
    public const SB_LGET_LSET                    = 355; // local.get $src + local.set $dst              → [src, dst]
    public const SB_I64CONST_I64AND              = 356; // i64.const $c + i64.and                       → [c]
    public const SB_ICONST_I32AND                = 357; // i32.const $c + i32.and                       → [c]
    public const SB_I64LOAD_LTEE                 = 358; // i64.load $off + local.tee $y                 → [off, y]
    public const SB_LGET_I64LOAD                 = 359; // local.get $x + i64.load $off                 → [x, off]
    public const SB_LGET_I64CONST_I64LTU_BRIF      = 360; // local.get+i64.const+i64.lt_u+br_if         → [x, c, depth]
    public const SB_LGET_I64CONST_I64LTU_BRIF_LOOP = 361; // local.get+i64.const+i64.lt_u+br_if→loop   → [x, c, contIp]
    public const SB_LGET_I32ADD                  = 362; // local.get $x + i32.add (add to TOS)          → [x]
    public const SB_LGET_I64CONST                = 363; // local.get $x + i64.const $c                  → [x, c]
    public const SB_LGET_I64CONST_I64AND         = 364; // local.get $x + i64.const $c + i64.and        → [x, c]
    public const SB_ICONST_I32SHL                = 365; // i32.const $c + i32.shl (shift TOS left by c) → [c]
    public const SB_I32SUB_LTEE                  = 366; // i32.sub + local.tee $y                       → [y]
    public const SB_I64CONST_I64STORE            = 367; // i64.const $c + i64.store $off                → [c, off]
    public const SB_LGET_ICONST_I32STORE         = 368; // local.get $x + i32.const $c + i32.store $off → [x, c, off]
    public const SB_LGET_ICONST_I32AND           = 369; // local.get $x + i32.const $c + i32.and        → [x, c]
    public const SB_LGET_ICONST_I32SHL           = 370; // local.get $x + i32.const $c + i32.shl        → [x, c]
    public const SB_LGET_LGET_I32ADD             = 371; // local.get $a + local.get $b + i32.add        → [a, b]
}
