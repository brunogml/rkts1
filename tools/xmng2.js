/**
 * mng2.js
 * Converts romanization (per translit2.txt) to Unicode Traditional Mongolian.
 *
 * Rules derived from translit2.txt:
 *   - Multi-character romanizations are tried before single-character ones.
 *   - Where a codepoint has two romanizations (primary diacritic + ASCII fallback),
 *     both are registered; the longer/first wins in greedy matching.
 *   - U+180E (MVS) is encoded as "-" between letters within a word.
 *   - Spaces in the input become U+202F (Narrow No-Break Space), the conventional
 *     Mongolian word separator for rendering engines that need it; plain space also
 *     accepted and passed through as U+0020.
 *   - Unrecognised characters are passed through unchanged.
 */

(function (root, factory) {
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = factory();
  } else {
    root.mng2 = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {

  // ---------------------------------------------------------------------------
  // Mapping table: [ romanization, unicodeChar ]
  // Ordered longest-first within each group so greedy scan works correctly.
  // Where the txt lists two romanizations for one codepoint, both are added.
  // Conflicts resolved by table order (first entry wins).
  // ---------------------------------------------------------------------------
  const TABLE = [
    // Punctuation / special
    ['-',   '\u180E'],   // MVS (Mongolian Vowel Separator)
    ['@',   '\u1800'],   // ᠀
    [';',   '\u1802'],   // ᠂
    [',',   '\u1803'],   // ᠃
    [':',   '\u1804'],   // ᠄
    ['.',   '\u1805'],   // ᠅

    // Digits
    ['0',   '\u1810'],
    ['1',   '\u1811'],
    ['2',   '\u1812'],
    ['3',   '\u1813'],
    ['4',   '\u1814'],
    ['5',   '\u1815'],
    ['6',   '\u1816'],
    ['7',   '\u1817'],
    ['8',   '\u1818'],
    ['9',   '\u1819'],

    // ---- Multi-char sequences FIRST (longest match priority) ----

    // 3-char
    ['ṭh',  '\u188D'],   // ᢍ  (diacritic form)
    ['Th',  '\u188D'],   // ᢍ  (ASCII fallback)

    // 2-char diacritic
    ['ng',  '\u1829'],   // ᠩ
    ['ny',  '\u185B'],   // ᡛ  (ASCII fallback for ñ)
    ['sh',  '\u1831'],   // ᠱ  (ASCII fallback for š)
    ['kh',  '\u183B'],   // ᠻ
    ['ph',  '\u1893'],   // ᢓ
    ['ts',  '\u183C'],   // ᠼ
    ['dz',  '\u1896'],   // ᢖ
    ['lh',  '\u1840'],   // ᡀ
    ['zh',  '\u183F'],   // ᠿ  (U+183F listed before U+1841 in table)
    ['ch',  '\u1842'],   // ᡂ
    ['tt',  '\u1890'],   // ᢐ  (ASCII fallback for ṱ)
    ['dd',  '\u1891'],   // ᢑ  (ASCII fallback for ḓ)

    // ---- Single-char diacritics ----
    ['ö',   '\u1825'],   // ᠥ
    ['ü',   '\u1826'],   // ᠦ
    ['ê',   '\u1827'],   // ᠧ
    ['ñ',   '\u185B'],   // ᡛ
    ['ṇ',   '\u188F'],   // ᢏ
    ['š',   '\u1831'],   // ᠱ
    ['č',   '\u1834'],   // ᠴ
    ['ǰ',   '\u1835'],   // ᠵ
    ['ž',   '\u183F'],   // ᠿ
    ['ṭ',   '\u188C'],   // ᢌ
    ['ḍ',   '\u188E'],   // ᢎ
    ['ṱ',   '\u1890'],   // ᢐ
    ['ḓ',   '\u1891'],   // ᢑ
    ['ẓ',   '\u185C'],   // ᡜ
    ['ɣ',   '\u182D'],   // ᠭ
    ['ṃ',   '\u1880'],   // ᢀ
    ['ḥ',   '\u1881'],   // ᢁ
    ['ý',   '\u18A3'],   // ᢣ

    // ---- Single-char ASCII uppercase (used as fallbacks in table) ----
    ['N',   '\u188F'],   // ᢏ  ṇ fallback
    ['T',   '\u188C'],   // ᢌ  ṭ fallback
    ['D',   '\u188E'],   // ᢎ  ḍ fallback
    ['Z',   '\u185C'],   // ᡜ  ẓ fallback
    ['J',   '\u1852'],   // ᡒ  (separate from ǰ U+1835)
    ['M',   '\u1880'],   // ᢀ  ṃ fallback
    ['H',   '\u1881'],   // ᢁ  ḥ fallback

    // ---- Plain ASCII letters ----
    ['a',   '\u1820'],   // ᠠ
    ['e',   '\u1821'],   // ᠡ
    ['i',   '\u1822'],   // ᠢ
    ['o',   '\u1823'],   // ᠣ
    ['u',   '\u1824'],   // ᠤ
    ['n',   '\u1828'],   // ᠨ
    ['b',   '\u182A'],   // ᠪ
    ['p',   '\u182B'],   // ᠫ
    ['f',   '\u1839'],   // ᠹ
    ['k',   '\u183A'],   // ᠺ
    ['q',   '\u182C'],   // ᠬ
    ['g',   '\u182D'],   // ᠭ
    ['m',   '\u182E'],   // ᠮ
    ['l',   '\u182F'],   // ᠯ
    ['s',   '\u1830'],   // ᠰ
    ['t',   '\u1832'],   // ᠲ
    ['d',   '\u1833'],   // ᠳ
    ['c',   '\u1834'],   // ᠴ  (ASCII fallback for č)
    ['z',   '\u183D'],   // ᠽ
    ['j',   '\u1852'],   // ᡒ  (j listed before ǰ in table)
    ['y',   '\u1836'],   // ᠶ
    ['r',   '\u1837'],   // ᠷ
    ['w',   '\u1838'],   // ᠸ
    ['h',   '\u183E'],   // ᠾ
    ["'",   '\u1889'],   // ᢉ
  ];

  // Pre-sort by romanization length descending (greedy longest-match).
  // Stable sort preserves original order for same-length entries.
  const SORTED = TABLE.slice().sort((a, b) => b[0].length - a[0].length);

  // Max romanization length (for scan window)
  const MAX_LEN = SORTED[0][0].length;

  // ---------------------------------------------------------------------------
  // Core conversion function
  // ---------------------------------------------------------------------------

  /**
   * Convert a romanization string to Unicode Traditional Mongolian.
   *
   * @param {string} input   Romanized text
   * @param {boolean} [useNNBS=true]  Replace space with U+202F (Narrow No-Break Space)
   * @returns {string}  Unicode Mongolian string
   */
  function toMongolian(input, useNNBS = true) {
    if (!input) return '';
    let out = '';
    let i = 0;
    while (i < input.length) {
      // Pass through whitespace (optionally converting space → NNBS)
      const ch = input[i];
      if (ch === ' ' || ch === '\u202F') {
        out += useNNBS ? '\u202F' : ' ';
        i++;
        continue;
      }
      if (ch === '\n' || ch === '\r' || ch === '\t') {
        out += ch;
        i++;
        continue;
      }

      // Greedy longest-match scan
      let matched = false;
      const remaining = input.slice(i);
      for (const [rom, uni] of SORTED) {
        if (remaining.startsWith(rom)) {
          out += uni;
          i += rom.length;
          matched = true;
          break;
        }
      }
      if (!matched) {
        // Unknown character: pass through
        out += ch;
        i++;
      }
    }
    return out;
  }

  // ---------------------------------------------------------------------------
  // Reverse map: Unicode Mongolian → romanization (primary form only)
  // ---------------------------------------------------------------------------

  // Build reverse map (first romanization per codepoint = canonical)
  const REVERSE_MAP = new Map();
  for (const [rom, uni] of TABLE) {
    if (!REVERSE_MAP.has(uni)) {
      REVERSE_MAP.set(uni, rom);
    }
  }

  /**
   * Convert Unicode Traditional Mongolian back to romanization.
   * Uses the primary (first-listed) romanization for each codepoint.
   *
   * @param {string} input  Mongolian Unicode string
   * @returns {string}  Romanized string
   */
  function toRoman(input) {
    if (!input) return '';
    let out = '';
    for (const ch of input) {
      if (ch === '\u202F' || ch === '\u00A0') { out += ' '; continue; }
      out += REVERSE_MAP.has(ch) ? REVERSE_MAP.get(ch) : ch;
    }
    return out;
  }

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------
  return { toMongolian, toRoman, TABLE, SORTED };

}));