#!/usr/bin/env python3
"""
stdmng.py — Update standard.xml:
  1. For each <item> in standard.xml, if its <rkts> matches an <item> in Mmk.xml,
     overwrite <mng> with the value from Mmk.xml.
  2. Delete all <mng1> elements.
"""

import re
import sys

MMK_FILE    = "Mmk.xml"
STD_FILE    = "standard.xml"
OUTPUT_FILE = "standard.xml"   # overwrite in place; change if you want a backup

# ── 1. Build lookup: rkts → mng from Mmk.xml ────────────────────────────────

def parse_items(xml_text):
    """Yield ((cat, rkts), mng) pairs from raw XML text."""
    for item in re.finditer(r'<item>(.*?)</item>', xml_text, re.DOTALL):
        body = item.group(1)
        rkts_m = re.search(r'<rkts>(.*?)</rkts>', body)
        cat_m  = re.search(r'<cat>(.*?)</cat>',   body)
        mng_m  = re.search(r'<mng>(.*?)</mng>',   body)
        if rkts_m and cat_m and mng_m:
            key = (cat_m.group(1).strip(), rkts_m.group(1).strip())
            yield key, mng_m.group(1).strip()

with open(MMK_FILE, encoding="utf-8") as f:
    mmk_text = f.read()

mmk_lookup = dict(parse_items(mmk_text))
print(f"Loaded {len(mmk_lookup)} entries from {MMK_FILE}")

# ── 2. Process standard.xml ──────────────────────────────────────────────────

with open(STD_FILE, encoding="utf-8") as f:
    std_text = f.read()

updated = 0
skipped = 0

def replace_item(m):
    global updated, skipped
    body = m.group(1)

    rkts_m = re.search(r'<rkts>(.*?)</rkts>',       body)
    cat_m  = re.search(r'<rktsTyp>(.*?)</rktsTyp>', body)
    if not rkts_m or not cat_m:
        return m.group(0)
    key = (cat_m.group(1).strip(), rkts_m.group(1).strip())

    if key in mmk_lookup:
        new_mng = mmk_lookup[key]
        body = re.sub(r'<mng>.*?</mng>', f'<mng>{new_mng}</mng>', body, count=1)
        updated += 1
    else:
        skipped += 1

    # Delete <mng1>…</mng1> (may be empty or have content)
    body = re.sub(r'<mng1>.*?</mng1>', '', body)

    return f'<item>{body}</item>'

result = re.sub(r'<item>(.*?)</item>', replace_item, std_text, flags=re.DOTALL)

with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
    f.write(result)

print(f"<mng> updated : {updated}")
print(f"<mng> unchanged (no Mmk match): {skipped}")
print(f"All <mng1> elements removed.")
print(f"Written to {OUTPUT_FILE}")