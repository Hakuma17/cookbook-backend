#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
อ่านข้อความ UTF-8 จาก STDIN → คืน JSON list ของ token (PyThaiNLP newmm)
"""

import sys, json, os
from pythainlp.tokenize import word_tokenize


def main() -> None:
    # ใช้ buffer + decode เพื่อบังคับ UTF-8 (errors='ignore' กันอักขระเสีย)
    text = sys.stdin.buffer.read().decode("utf-8", errors="ignore").strip()

    toks = word_tokenize(text, engine="newmm", keep_whitespace=False)

    # กำจัด token ว่าง/ซ้ำ แล้วจำกัด 5 คำ
    uniq = []
    for t in toks:
        if t and t not in uniq:
            uniq.append(t)
        if len(uniq) >= 5:
            break

    print(json.dumps(uniq, ensure_ascii=False))


if __name__ == "__main__":
    # ตั้งโฟลเดอร์แคชของ PyThaiNLP (ลดสิทธิ์เขียน /tmp ใน windows จะถูกข้าม)
    os.environ.setdefault("PYTHAINLP_DATA_HOME", "/tmp/pythainlp-data")
    main()
