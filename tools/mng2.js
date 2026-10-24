/**
 * mng2.js — Bidirectional converter: transliteration ↔ Unicode Traditional Mongolian
 * Map source: translit2.txt (one romanization per codepoint, no ambiguity)
 *
 * Exports:
 *   toUnicode(translit)  — romanization string → Unicode Mongolian
 *   toTranslit(unicode)  — Unicode Mongolian → romanization string
 *
 * Plain <script> context: window.mng2 = { toUnicode, toTranslit }
 */

(function (root, factory) {
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = factory();
  } else {
    root.mng2 = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {

  // Map: [ unicodeChar, romanization ] — table order = translit2.txt order
  const TABLE = [
    ['\u180E', '-'],    // U+180E  MVS
    ['\u1800', '@'],    // U+1800  ᠀
    ['\u1802', ';'],    // U+1802  ᠂
    ['\u1803', ','],    // U+1803  ᠃
    ['\u1804', ':'],    // U+1804  ᠄
    ['\u1805', '.'],    // U+1805  ᠅
    ['\u1810', '0'],    // U+1810  ᠐
    ['\u1811', '1'],    // U+1811  ᠑
    ['\u1812', '2'],    // U+1812  ᠒
    ['\u1813', '3'],    // U+1813  ᠓
    ['\u1814', '4'],    // U+1814  ᠔
    ['\u1815', '5'],    // U+1815  ᠕
    ['\u1816', '6'],    // U+1816  ᠖
    ['\u1817', '7'],    // U+1817  ᠗
    ['\u1818', '8'],    // U+1818  ᠘
    ['\u1819', '9'],    // U+1819  ᠙
    ['\u1820', 'a'],    // U+1820  ᠠ
    ['\u1821', 'e'],    // U+1821  ᠡ
    ['\u1822', 'i'],    // U+1822  ᠢ
    ['\u1823', 'o'],    // U+1823  ᠣ
    ['\u1824', 'u'],    // U+1824  ᠤ
    ['\u1825', 'ö'],    // U+1825  ᠥ
    ['\u1826', 'ü'],    // U+1826  ᠦ
    ['\u1827', 'ê'],    // U+1827  ᠧ
    ['\u1828', 'n'],    // U+1828  ᠨ
    ['\u185B', 'ny'],   // U+185B  ᡛ
    ['\u188F', '\u1E47'],  // U+188F  ᢏ  ṇ
    ['\u1829', 'ng'],   // U+1829  ᠩ
    ['\u182A', 'b'],    // U+182A  ᠪ
    ['\u182B', 'p'],    // U+182B  ᠫ
    ['\u1893', 'ph'],   // U+1893  ᢓ
    ['\u1839', 'f'],    // U+1839  ᠹ
    ['\u183A', 'k'],    // U+183A  ᠺ
    ['\u183B', 'kh'],   // U+183B  ᠻ
    ['\u182C', 'q'],    // U+182C  ᠬ
    ['\u182D', 'γ'],  // U+182D  ᠭ  ɣ
    ['\u182E', 'm'],    // U+182E  ᠮ
    ['\u182F', 'l'],    // U+182F  ᠯ
    ['\u1830', 's'],    // U+1830  ᠰ
    ['\u1831', '\u0161'],  // U+1831  ᠱ  š
    ['\u1832', 't'],    // U+1832  ᠲ
    ['\u1833', 'd'],    // U+1833  ᠳ
    ['\u188C', '\u1E6D'],  // U+188C  ᢌ  ṭ
    ['\u188D', '\u1E6Dh'], // U+188D  ᢍ  ṭh
    ['\u188E', '\u1E0D'],  // U+188E  ᢎ  ḍ
    ['\u1890', '\u1E71'],  // U+1890  ᢐ  ṱ
    ['\u1891', '\u1E13'],  // U+1891  ᢑ  ḓ
    ['\u1834', '\u010D'],  // U+1834  ᠴ  č
    ['\u183C', 'ts'],   // U+183C  ᠼ
    ['\u183D', 'z'],    // U+183D  ᠽ
    ['\u1852', 'j'],    // U+1852  ᡒ
    ['\u1896', 'dz'],   // U+1896  ᢖ
    ['\u185C', '\u1E93'],  // U+185C  ᡜ  ẓ
    ['\u1835', '\u01F0'],  // U+1835  ᠵ  ǰ
    ['\u1836', 'y'],    // U+1836  ᠶ
    ['\u1837', 'r'],    // U+1837  ᠷ
    ['\u1838', 'w'],    // U+1838  ᠸ
    ['\u183E', 'h'],    // U+183E  ᠾ
    ['\u183F', '\u017E'],  // U+183F  ᠿ  ž
    ['\u1840', 'lh'],   // U+1840  ᡀ
    ['\u1841', 'zh'],   // U+1841  ᡁ
    ['\u1842', 'ch'],   // U+1842  ᡂ
    ['\u1880', '\u1E43'],  // U+1880  ᢀ  ṃ
    ['\u1881', '\u1E25'],  // U+1881  ᢁ  ḥ
    ['\u1889', "'"],    // U+1889  ᢉ
    ['\u18A3', '\u00FD'],  // U+18A3  ᢣ  ý
  ];

  // Forward: sorted longest-first for greedy matching
  const FORWARD = TABLE.slice().sort((a, b) => b[1].length - a[1].length);

  // Reverse: simple Map lookup
  const REVERSE = new Map(TABLE.map(([uni, rom]) => [uni, rom]));

  function toUnicode(input) {
    if (!input) return '';
    let out = '';
    let i = 0;
    while (i < input.length) {
      const ch = input[i];
      if (ch === ' ' || ch === '\n' || ch === '\r' || ch === '\t') {
        out += ch; i++; continue;
      }
      let matched = false;
      for (const [uni, rom] of FORWARD) {
        if (input.startsWith(rom, i)) {
          out += uni;
          i += rom.length;
          matched = true;
          break;
        }
      }
      if (!matched) { out += ch; i++; }
    }
    return out;
  }

  function toTranslit(input) {
    if (!input) return '';
    let out = '';
    for (const ch of input) {
      if (ch === '\u202F' || ch === '\u00A0') { out += ' '; continue; }
      if (ch === ' ' || ch === '\n' || ch === '\r' || ch === '\t') { out += ch; continue; }
      out += REVERSE.has(ch) ? REVERSE.get(ch) : ch;
    }
    return out;
  }

  return { toUnicode, toTranslit, TABLE };
}));