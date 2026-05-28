"""Tiny PO->MO compiler (GNU MO format). Run after editing the .po:

    python locales/_compile_mo.py locales/ru_RU.po locales/ru_RU.mo

Supports plural forms (msgid_plural / msgstr[N]). msgctxt is NOT supported.
"""
from __future__ import annotations
import re
import struct
import sys
from pathlib import Path

STRING_RE = re.compile(r'^"((?:\\.|[^"\\])*)"\s*$')
INDEXED_RE = re.compile(r'^msgstr\[(\d+)\]\s+(.*)$')


def unescape(s: str) -> str:
    return (
        s.replace(r"\n", "\n")
         .replace(r"\t", "\t")
         .replace(r"\r", "\r")
         .replace(r"\"", "\"")
         .replace(r"\\", "\\")
    )


def parse_po(text: str) -> list[tuple[bytes, bytes]]:
    """Parse PO into list of (mo_key, mo_value) byte pairs.

    For plural entries the key is `msgid\\x00msgid_plural` and the value is
    `msgstr[0]\\x00msgstr[1]\\x00...`.
    """
    entries: list[tuple[bytes, bytes]] = []

    msgid_buf: list[str] = []
    msgid_plural_buf: list[str] = []
    msgstr_buf: list[str] = []
    msgstr_plural: dict[int, list[str]] = {}
    state = None
    plural_idx = -1

    def commit():
        nonlocal msgid_buf, msgid_plural_buf, msgstr_buf, msgstr_plural
        if not (msgid_buf or msgstr_buf or msgstr_plural):
            return
        msgid = unescape("".join(msgid_buf))
        if msgid_plural_buf:
            msgid_plural = unescape("".join(msgid_plural_buf))
            indices = sorted(msgstr_plural.keys())
            forms = [unescape("".join(msgstr_plural[i])) for i in indices]
            # Skip if all forms empty
            if any(forms):
                entries.append((
                    (msgid + "\x00" + msgid_plural).encode("utf-8"),
                    ("\x00".join(forms)).encode("utf-8"),
                ))
        else:
            msgstr = unescape("".join(msgstr_buf))
            if msgstr or msgid == "":
                entries.append((msgid.encode("utf-8"), msgstr.encode("utf-8")))

    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("msgid_plural "):
            state = "id_plural"
            m = STRING_RE.match(line[len("msgid_plural "):])
            if m:
                msgid_plural_buf.append(m.group(1))
            continue
        if line.startswith("msgid "):
            commit()
            msgid_buf, msgid_plural_buf = [], []
            msgstr_buf = []
            msgstr_plural = {}
            plural_idx = -1
            state = "id"
            m = STRING_RE.match(line[len("msgid "):])
            if m:
                msgid_buf.append(m.group(1))
            continue
        m = INDEXED_RE.match(line)
        if m:
            plural_idx = int(m.group(1))
            msgstr_plural[plural_idx] = []
            state = "str_plural"
            mm = STRING_RE.match(m.group(2))
            if mm:
                msgstr_plural[plural_idx].append(mm.group(1))
            continue
        if line.startswith("msgstr "):
            state = "str"
            m = STRING_RE.match(line[len("msgstr "):])
            if m:
                msgstr_buf.append(m.group(1))
            continue
        m = STRING_RE.match(line)
        if m:
            if state == "id":
                msgid_buf.append(m.group(1))
            elif state == "id_plural":
                msgid_plural_buf.append(m.group(1))
            elif state == "str":
                msgstr_buf.append(m.group(1))
            elif state == "str_plural" and plural_idx >= 0:
                msgstr_plural[plural_idx].append(m.group(1))
    commit()
    return entries


def write_mo(entries: list[tuple[bytes, bytes]], out: Path) -> None:
    """Write a GNU MO file."""
    entries = sorted(entries, key=lambda kv: kv[0])
    n = len(entries)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + sum(len(k) + 1 for k, _ in entries)

    key_offsets: list[tuple[int, int]] = []
    value_offsets: list[tuple[int, int]] = []
    koff, voff = keystart, valuestart
    for k, v in entries:
        key_offsets.append((len(k), koff))
        koff += len(k) + 1
        value_offsets.append((len(v), voff))
        voff += len(v) + 1

    out_bytes = bytearray()
    out_bytes += struct.pack(
        "Iiiiiii",
        0x950412DE,
        0,
        n,
        7 * 4,
        7 * 4 + 8 * n,
        0,
        0,
    )
    for length, off in key_offsets:
        out_bytes += struct.pack("ii", length, off)
    for length, off in value_offsets:
        out_bytes += struct.pack("ii", length, off)
    for k, _ in entries:
        out_bytes += k + b"\x00"
    for _, v in entries:
        out_bytes += v + b"\x00"

    out.write_bytes(bytes(out_bytes))


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("usage: _compile_mo.py <input.po> <output.mo>", file=sys.stderr)
        return 1
    src, dst = Path(argv[1]), Path(argv[2])
    entries = parse_po(src.read_text(encoding="utf-8"))
    write_mo(entries, dst)
    print(f"compiled {len(entries)} entries -> {dst}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
