/**
 * mng.js — Mongolian romanization → Traditional Mongolian Unicode script converter
 * Ported from unimng.php (unicodemng function)
 *
 * Usage:
 *   import { unicodemng } from './mng.js';
 *   const result = unicodemng("mongγol");
 *
 * Or in a plain <script> context the function is exposed as window.unicodemng.
 */

/**
 * Convert a romanized Mongolian string to traditional Mongolian Unicode script.
 *
 * Input conventions
 * ─────────────────
 * The romanization uses single-quote ( ' ) and double-quote ( " ) as
 * diacritic-replacement suffixes, and asterisk ( * ) for a third series.
 * These multi-char codes must be resolved before any single-char rule fires.
 *
 * Conversion order
 * ─────────────────
 *  1. Suffix forms  (-aca/-ece, -iyar/-iyer, -bar/-ber, -luga/-lüge,
 *                    -tai/-tei, -ban/-ben, -yin/-in, -a/-e/-i/-u/-ü/-un/-ün,
 *                    -dur/-dür/-tür)
 *  2. Multi-char input codes (longest/most-specific first):
 *       asterisk series  (d*, s*)
 *       quote/diacritic series  (c', c", d', d", e', g', h', j', j", k',
 *                                m', n', n", o', p', s', t', t", y', z')
 *       two-char romanization digraphs  (kh, ph, lh, ts, dz, ng, ny, gh, ch, sh, zh, th, tt, dd)
 *       diacritic aliases from translit2  (ṭh, ṭ, ḓ, ṱ, ẓ, ḥ, ý, ñ, ê)
 *  3. Vowel-context ke/ki/kü/kö
 *  4. Individual vowels and consonants
 *  5. Punctuation
 *  6. Residual hyphen clean-up
 *
 * @param {string} input  Romanized Mongolian text
 * @returns {string}      Traditional Mongolian Unicode text
 */
export function unicodemng(input) {
  // Prepend a space (positional marker for suffix matching, as in the PHP original)
  let s = ' ' + input;

  // ── 1. Suffix forms ───────────────────────────────────────────────────────
  // Ablative
  s = s.replaceAll('-aca',  ' \u1821\u1834\u1821');          // ᠡᠴᠡ
  s = s.replaceAll('-ece',  ' \u1821\u1834\u1821');          // ᠡᠴᠡ
  // Instrumental
  s = s.replaceAll('-iyar', ' \u1822\u1836\u1820\u1837');    // ᠢᠶᠠᠷ
  s = s.replaceAll('-iyer', ' \u1822\u1836\u1821\u1837');    // ᠢᠶᠡᠷ
  s = s.replaceAll('-bar',  ' \u182A\u1820\u1837');          // ᠪᠠᠷ
  s = s.replaceAll('-ber',  ' \u182A\u1821\u1837');          // ᠪᠡᠷ
  // Comitative
  s = s.replaceAll('-luga', ' \u182F\u1824\u182D\u1820');    // ᠯᠤᠭᠠ
  s = s.replaceAll('-lug-a', ' \u182F\u1824\u182D \u1820');    // ᠯᠤᠭ ᠠ
  s = s.replaceAll('-lüge', ' \u182F\u1826\u182D\u1821');    // ᠯᠦᠭᠡ
  // Adjectival -tai / -tei
  s = s.replaceAll('-tai',  ' \u1832\u1820\u1822');          // ᠲᠠᠢ
  s = s.replaceAll('-tei',  ' \u1832\u1821\u1822');          // ᠲᠡᠢ
  // Reflexive accusative -ban / -ben
  s = s.replaceAll('-ban',  ' \u182A\u1820\u1828');          // ᠪᠠᠨ
  s = s.replaceAll('-ben',  ' \u182A\u1821\u1828');          // ᠪᠡᠨ
  // Genitive -yin / -in
  s = s.replaceAll('-yin',  ' \u1836\u1822\u1828');          // ᠶᠢᠨ
  s = s.replaceAll('-in',   ' \u1822\u1828');                // ᠢᠨ
  // Short vowel enclitics (MVS forms)
  s = s.replaceAll('-a',   ' \u1820');   // MVS + ᠠ  (enclitic -a)
  s = s.replaceAll('-i',   ' \u1822');        // ᠢ
  s = s.replaceAll('-e',   ' \u1821');   // MVS + ᠡ  (enclitic -e)
  s = s.replaceAll('-un',  ' \u1824\u1828');  // ᠤᠨ
  s = s.replaceAll('-ün',  ' \u1824\u1828');  // ᠤᠨ
  s = s.replaceAll('-u',   ' \u1824');        // ᠤ
  s = s.replaceAll('-ü',   ' \u1824');        // ᠤ
  // Dative-locative: -tur/-tür → ᠳᠣᠷ  /  -dur/-dür → ᠳ᠋‍‍ᠤᠷ (FVS1+ZWJ form)
  s = s.replaceAll('-tür', ' \u1833\u1823\u1837');              // ᠳᠣᠷ
  s = s.replaceAll('-tur', ' \u1833\u1823\u1837');              // ᠳᠣᠷ
  s = s.replaceAll('-dür', ' \u1833\u180B\u200D\u200D\u1824\u200D\u1837'); // ᠳ᠋‍‍ᠤ‍ᠷ
  s = s.replaceAll('-dur', ' \u1833\u180B\u200D\u200D\u1824\u200D\u1837'); // ᠳ᠋‍‍ᠤ‍ᠷ

  // ── 2. Multi-char input codes (longest / most-specific first) ─────────────

  // Asterisk series
  s = s.replaceAll('d*',  '\u188E');  // ᢎ  MONGOLIAN LETTER TODO DA
  s = s.replaceAll('ḍ',  '\u188E');  // ᢎ  MONGOLIAN LETTER TODO DA
  s = s.replaceAll('ṇ',  '\u188F');  // ᢏ  MONGOLIAN LETTER MANCHU NG
  s = s.replaceAll('s*',  '\u188D');  // ᢍ  MONGOLIAN LETTER MANCHU SHA
  s = s.replaceAll('n*', '\u1829');  // ᠩ  ANG

  // Diacritic single-char aliases (from translit2 map)
  s = s.replaceAll('ṭh', '\u188D');  // ᢍ  MONGOLIAN LETTER MANCHU SHA  (= th / s*)
  s = s.replaceAll('ṭ',  '\u188C');  // ᢌ  MONGOLIAN LETTER MANCHU TA   (= t')
  s = s.replaceAll('ḓ',  '\u1891');  // ᢑ  MONGOLIAN LETTER MANCHU DA   (= d')
  s = s.replaceAll('ṱ',  '\u1890');  // ᢐ  MONGOLIAN LETTER TODO TA      (= d" / T)
  s = s.replaceAll('ẓ',  '\u185C');  // ᡜ  MONGOLIAN LETTER TODO DZA     (= z / C)
  s = s.replaceAll('ḥ',  '\u1881');  // ᢁ  MONGOLIAN LETTER ALI GALI DAMARU (= h')
  s = s.replaceAll('ý',  '\u18A3');  // ᢣ  MONGOLIAN LETTER MANCHU YA    (= y')
  s = s.replaceAll('ñ',  '\u185B');  // ᡛ  MONGOLIAN LETTER TODO NYA      (= ny / n' / Y)
  s = s.replaceAll('ê',  '\u1827');  // ᠧ  MONGOLIAN LETTER EE            (= E)
  s = s.replaceAll("ṣ",  '\u1894');  // ᢔ  MONGOLIAN LETTER MANCHU SHA (alt)

  // Long vowels: vowel + " → doubled vowel glyph
  s = s.replaceAll('a"',  '\u1820\u1820');  // ᠠᠠ  long A
  s = s.replaceAll('ā',  '\u1820\u1820');  // ᠠᠠ  long A
  s = s.replaceAll('e"',  '\u1821\u1821');  // ᠡᠡ  long E
  s = s.replaceAll('i"',  '\u1822\u1822');  // ᠢᠢ  long I
  s = s.replaceAll('Ī',  '\u1822\u1822');  // ᠢᠢ  long I
  s = s.replaceAll('o"',  '\u1823\u1823');  // ᠣᠣ  long O
  s = s.replaceAll('u"',  '\u1824\u1824');  // ᠤᠤ  long U
  s = s.replaceAll('ū',  '\u1824\u1824');  // ᠤᠤ  long U
  s = s.replaceAll('ö"',  '\u1825\u1825');  // ᠥᠥ  long OE
  s = s.replaceAll('ü"',  '\u1826\u1826');  // ᠦᠦ  long UE

  // Quote / diacritic series  (' and ")
  s = s.replaceAll("c'",  '\u188B');  // ᢋ  MONGOLIAN LETTER MANCHU CHA
  s = s.replaceAll('c"',  '\u183C');  // ᠼ  MONGOLIAN LETTER CHA (= ts)
  s = s.replaceAll("d'",  '\u1891');  // ᢑ  MONGOLIAN LETTER MANCHU DA
  s = s.replaceAll('d"',  '\u1890');  // ᢐ  MONGOLIAN LETTER TODO TA
  s = s.replaceAll("e'",  '\u1838');  // ᠸ  MONGOLIAN LETTER WA (= v)
  s = s.replaceAll("g'",  '\u183A');  // ᠺ  MONGOLIAN LETTER KA (front-vowel k)
  s = s.replaceAll("h'",  '\u1881');  // ᢁ  MONGOLIAN LETTER ALI GALI DAMARU
  s = s.replaceAll("j'",  '\u183D');  // ᠽ  MONGOLIAN LETTER DZA (= dz)
  s = s.replaceAll('j"',  '\u1854');  // ᡔ  MONGOLIAN LETTER TODO YA
  s = s.replaceAll("k'",  '\u183B');  // ᠻ  MONGOLIAN LETTER KHA (= kh / K)
  s = s.replaceAll("m'",  '\uE000');  // sentinel: ᢀ prefix — moved to word-start in post-processing
  s = s.replaceAll("n'",  '\u185B');  // ᡛ  MONGOLIAN LETTER TODO NYA (= ny / Y)
  s = s.replaceAll('n"',  '\u188F');  // ᢏ  MONGOLIAN LETTER MANCHU NG (= N)
  s = s.replaceAll("o'",  '\u1823\u180D\u180D\u1838\u1821\u1821'); // ᠣ᠍᠍ᠸᠡᠡ
  s = s.replaceAll("p'",  '\u1893');  // ᢓ  MONGOLIAN LETTER MANCHU P
  s = s.replaceAll("s'",  '\u1894');  // ᢔ  MONGOLIAN LETTER MANCHU SHA (alt)
  s = s.replaceAll("t'",  '\u188C');  // ᢌ  MONGOLIAN LETTER MANCHU TA
  s = s.replaceAll('t"',  '\u1832');  // ᠲ  MONGOLIAN LETTER TA (= t)
  s = s.replaceAll("y'",  '\u18A3');  // ᢣ  MONGOLIAN LETTER MANCHU YA
  s = s.replaceAll("z'",  '\u1895');  // ᢕ  MONGOLIAN LETTER MANCHU ZA
  s = s.replaceAll("'",   '\u1863');  // ᡣ  standalone apostrophe (catch-all)

  // Two-char romanization digraphs
  s = s.replaceAll('kh', '\u183B');  // ᠻ  KHA
  s = s.replaceAll('ph', '\u1893');  // ᢓ  MONGOLIAN LETTER MANCHU P    (= p')
  s = s.replaceAll('lh', '\u1840');  // ᡀ  MONGOLIAN LETTER TODO LHA
  s = s.replaceAll('ts', '\u183C');  // ᠼ  TSA
  s = s.replaceAll('dz', '\u183D');  // ᠽ  DZA
  s = s.replaceAll('ng', '\u1829');  // ᠩ  ANG
  s = s.replaceAll('ny', '\u185B');  // ᡛ  TODO NYA
  s = s.replaceAll('gh', '\u182D');  // ᠭ  GA  (alt romanization for γ)
  s = s.replaceAll('ch', '\u1834');  // ᠴ  CHA (alt for č)
  s = s.replaceAll('sh', '\u1831');  // ᠱ  SHA (alt for š)
  s = s.replaceAll('zh', '\u1835');  // ᠵ  JA  (alt romanization)
  s = s.replaceAll('th', '\u188D');  // ᢍ  ThA
  s = s.replaceAll('tt', '\u1890');  // ᢐ  MONGOLIAN LETTER TODO TA      (= d" / T / ṱ)
  s = s.replaceAll('dd', '\u1891');  // ᢑ  MONGOLIAN LETTER MANCHU DA    (= d' / ḓ)

  // ── 3. Vowel-context k variants (ke/ki/kü/kö → ᠭ + front vowel) ──────────
  s = s.replaceAll('ga', '\u182D\u1821');  // ᠭᠡ
  s = s.replaceAll('go', '\u182D\u1825');  // ᠭᠡ
  s = s.replaceAll('gu', '\u182D\u1825');  // ᠭᠡ
  s = s.replaceAll('ke', '\u182D\u1821');  // ᠭᠡ
  s = s.replaceAll('ki', '\u182D\u1822');  // ᠭᠢ
  s = s.replaceAll('kü', '\u182D\u1825');  // ᠭᠥ
  s = s.replaceAll('kö', '\u182D\u1825');  // ᠭᠥ

  // ── 4a. Vowels ────────────────────────────────────────────────────────────
  // Uppercase A/I/O/U = long vowel (doubled); E is reserved for ᠧ barred-e
  s = s.replaceAll('A', '\u1820\u1820');  // ᠠᠠ  long A
  s = s.replaceAll('I', '\u1822\u1822');  // ᠢᠢ  long I
  s = s.replaceAll('O', '\u1823\u1823');  // ᠣᠣ  long O
  s = s.replaceAll('U', '\u1824\u1824');  // ᠤᠤ  long U
  s = s.replaceAll('a', '\u1820');  // ᠠ  A
  s = s.replaceAll('e', '\u1821');  // ᠡ  E
  s = s.replaceAll('i', '\u1822');  // ᠢ  I
  s = s.replaceAll('o', '\u1823');  // ᠣ  O
  s = s.replaceAll('u', '\u1824');  // ᠤ  U
  s = s.replaceAll('ö', '\u1825');  // ᠥ  OE
  s = s.replaceAll('ü', '\u1826');  // ᠦ  UE
  s = s.replaceAll('E', '\u1827');  // ᠧ  EE (barred e / second-e in some romanizations)

  // ── 4b. Consonants ────────────────────────────────────────────────────────
  s = s.replaceAll('n', '\u1828');  // ᠨ  NA
  s = s.replaceAll('b', '\u182A');  // ᠪ  BA
  s = s.replaceAll('p', '\u182B');  // ᠫ  PA
  s = s.replaceAll('q', '\u182C');  // ᠬ  QA
  s = s.replaceAll('γ', '\u182D');  // ᠭ  GA (gamma)
  s = s.replaceAll('ɣ', '\u182D');  // ᠭ  GA (gamma)
  s = s.replaceAll('g', '\u182D');  // ᠭ  GA
  s = s.replaceAll('m', '\u182E');  // ᠮ  MA
  s = s.replaceAll('l', '\u182F');  // ᠯ  LA
  s = s.replaceAll('s', '\u1830');  // ᠰ  SA
  s = s.replaceAll('S', '\u1831');  // ᠱ  SHA
  s = s.replaceAll('š', '\u1831');  // ᠱ  SHA
  s = s.replaceAll('t', '\u1832');  // ᠲ  TA
  s = s.replaceAll('d', '\u1833');  // ᠳ  DA
  s = s.replaceAll('c', '\u1834');  // ᠴ  CHA
  s = s.replaceAll('č', '\u1834');  // ᠴ  CHA
  s = s.replaceAll('j', '\u1835');  // ᠵ  JA
  s = s.replaceAll('ǰ', '\u1835');  // ᠵ  JA
  s = s.replaceAll('y', '\u1836');  // ᠶ  YA
  s = s.replaceAll('r', '\u1837');  // ᠷ  RA
  s = s.replaceAll('v', '\u1838');  // ᠸ  WA
  s = s.replaceAll('w', '\u1838');  // ᠸ  WA  (alt input)
  s = s.replaceAll('f', '\u1839');  // ᠹ  FA
  s = s.replaceAll('k', '\u183A');  // ᠺ  KA  (residual after digraphs/ke-ki handled)
  s = s.replaceAll('h', '\u183E');  // ᠾ  HA
  s = s.replaceAll('H', '\u1841');  // ᡁ  TODO LONG HALF H
  s = s.replaceAll('z', '\u185C');  // ᡜ  TODO DZA
  s = s.replaceAll('x', '\u182C');  // ᠬ  QA  (common alt romanization)

  // ── 4c. Uppercase legacy shorthands (kept for backward compatibility) ─────
  // These map to the same glyphs as the ' / " / * series above.
  s = s.replaceAll('D', '\u188E');  // ᢎ  = d*
  s = s.replaceAll('Ph', '\u1893');  // ᢓ  
  s = s.replaceAll('P', '\u1892');  // ᢒ  
  s = s.replaceAll('N', '\u188F');  // ᢏ  = n"
  s = s.replaceAll('T', '\u1890');  // ᢐ  = d"
  s = s.replaceAll('J', '\u183C');  // ᠼ  = ts / c"
  s = s.replaceAll('C', '\u185C');  // ᡜ  = z
  s = s.replaceAll('Y', '\u185B');  // ᡛ  = ny / n'
  s = s.replaceAll('M', '\uE000');  // sentinel: ᢀ prefix — moved to word-start in post-processing
  s = s.replaceAll('K', '\u183B');  // ᠻ  = kh / k'

  // ── 5. Punctuation ────────────────────────────────────────────────────────
  s = s.replaceAll('@',  '\u1800');  // ᠀  MONGOLIAN BIRGA
  s = s.replaceAll(';',  '\u1802');  // ᠂  MONGOLIAN COMMA (single)
  s = s.replaceAll('::', '\u1805');  // ᠅  MONGOLIAN FOUR DOTS
  s = s.replaceAll(',', '\u1803');  // ᠃  MONGOLIAN COMMA
  s = s.replaceAll(':', '\u1803');  // ᠃  MONGOLIAN COMMA
  s = s.replaceAll('.', '\u1805');  // ᠅  MONGOLIAN FOUR DOTS

  // ── 6. Residual hyphens ───────────────────────────────────────────────────
  s = s.replaceAll(' - ', ' ~ ');    // spaced hyphen → tilde separator
  s = s.replaceAll('-', ' '); // bare hyphen → space + MVS

  // ── 7. Post-processing: move ᢀ (ALI GALI ANUSVARA) to word start ─────────
  // M / m' marks the whole word — the glyph precedes it regardless of where
  // in the romanization M appears (e.g. phaM → ᢀᠫᠾᠠ).
  // Strategy: split on spaces, move any sentinel \uE000 to front of its token.
  s = s.split(' ').map(token => {
    if (token.includes('\uE000')) {
      return '\u1880' + token.replaceAll('\uE000', '');
    }
    return token;
  }).join(' ');

  // Strip the leading space added at the start
  return s.slice(1);
}

// CommonJS / plain-script fallback
if (typeof window !== 'undefined') {
  window.unicodemng = unicodemng;
}