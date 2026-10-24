/**
 * etextsearch.js  —  client-side e-text search engine
 * Shared by results2.html (standard) and results2x.html (customizable/binomes).
 *
 * Detects which page it is on by checking for #binomes-input.
 * All paths follow the rKTs convention:
 *   collections.json  →  ../data/general/collections.json
 *   catalog XML       →  ../data/collections/{sigla}/{sigla}.xml
 *   etext files       →  ../data/etexts/{genreNum}{sigla}/{volno:03}.txt
 */
(function () {
    'use strict';

    /* ── Constants ──────────────────────────────────────────────────────────── */
    const COLLS_URL  = '../data/general/collections.json';
    const COLL_BASE  = '../data/collections';
    const ETEXT_BASE = '../data/etexts';
    const MAX_GAP    = 5;
    const CTX_LEN    = 250;
    const IS_2X      = !!document.getElementById('binomes-input');  // results2x vs results2

    /* ── Collection groups (mirrors PHP) ────────────────────────────────────── */
    const COLLECTION_GROUPS = [
        { group: 'KANJUR', items: [
            { id: 'C',   label: 'C Cone Kanjur (OCR)',                          expert: true  },
            { id: 'D',   label: 'D Derge Kanjur (Input)',                        expert: false },
            { id: 'F',   label: 'F Phugbrag Kanjur (OCR)',                       expert: false },
            { id: 'H',   label: 'H Lhasa Kanjur (Input)',                        expert: true  },
            { id: 'He',  label: 'He Hemis I Kanjur (OCR)',                       expert: true  },
            { id: 'J',   label: 'J Lithang Kanjur (Input)',                      expert: true  },
            { id: 'L',   label: 'L London Kanjur (OCR)',                         expert: true  },
            { id: 'N',   label: 'N Narthang Kanjur (OCR)',                       expert: true  },
            { id: 'Q',   label: 'Q Peking Kanjur (OCR)',                         expert: true  },
            { id: 'S',   label: 'S Stog Kanjur (OCR)',                           expert: false },
            { id: 'Ts',  label: 'Ts Tsarang Kanjur (OCR)',                       expert: true  },
            { id: 'Ty',  label: 'Ty Tashiyangtse Kanjur (OCR)',                  expert: true  },
            { id: 'V',   label: 'V Ulaanbaatar Kanjur (OCR)',                    expert: true  },
        ]},
        { group: 'CANONICAL COLLECTIONS', items: [
            { id: 'Ba',  label: 'Ba Basgo Fragments (OCR)',                      expert: true  },
            { id: 'Dm',  label: 'Dm Drakmar Collection (OCR)',                   expert: true  },
            { id: 'Go',  label: 'Go Gondhla Collection (OCR)',                   expert: true  },
            { id: 'Ng',  label: 'Ng Namgyal Collection (OCR)',                   expert: false },
        ]},
        { group: 'TANJUR', items: [
            { id: 'TD',  label: 'D Derge Tanjur (Input)',                        expert: false },
            { id: 'TG',  label: 'G Golden Tanjur (OCR)',                         expert: true  },
            { id: 'TN',  label: 'N Narthang Tanjur (Input)',                     expert: true  },
            { id: 'TQ',  label: 'Q Peking Tanjur (OCR)',                         expert: true  },
        ]},
        { group: 'OLD TANTRA', items: [
            { id: 'Gaz', label: "Gaz Anzom Chögar Nyingyü Chudün (OCR)",         expert: true  },
            { id: 'Gbr', label: 'Gbr Vairocana Nyingyü (OCR)',                   expert: true  },
            { id: 'Gdg', label: 'Gdg Derge Nyingyü (OCR)',                       expert: true  },
            { id: 'Gkh', label: 'Gkh Kham Nyingyü (OCR)',                        expert: true  },
            { id: 'Gkk', label: 'Gkk Khakong Nyingyü (OCR)',                     expert: true  },
            { id: 'Glt', label: 'Glt Langthang Nyingyü Chudün (OCR)',            expert: true  },
            { id: 'Gpb', label: 'Gpb Paltsek Nyingyü (OCR)',                     expert: false },
            { id: 'Gtb', label: 'Gtb Tsamdrak Nyingyü (OCR)',                    expert: true  },
            { id: 'Gtn', label: 'Gtn Rikdzin Tsewang Norbu Nyingyü (OCR)',       expert: true  },
        ]},
        { group: 'BON CANON', items: [
            { id: 'Bkz', label: "Bkz Bon bka' brten (OCR)",                      expert: false },
        ]},
    ];

    /* ── HTML escape ─────────────────────────────────────────────────────────── */
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── EWTS / Unicode ─────────────────────────────────────────────────────── */
    function toUnicode(ewts) {
        if (!ewts) return '';
        if (window.jsEWTS) { try { return window.jsEWTS.fromWylie(ewts); } catch(e) {} }
        return ewts;
    }

    /* ── Collection config (mirrors PHP getCollectionCfg) ───────────────────── */
    function getCollCfg(collectionId, collections) {
        const tanjurMatch = collectionId.match(/^T([A-Z][a-z]*)$/);
        const sigla       = tanjurMatch ? tanjurMatch[1] : collectionId;
        const genreFilter = tanjurMatch ? 'tanjur' : null;

        for (const entry of collections) {
            if (entry.sigla !== sigla) continue;
            const genre = (entry.genre || '').toLowerCase();
            if (genreFilter && !genre.includes(genreFilter)) continue;

            let genreNum, dett;
            if (genre.includes('tanjur') && !genre.includes('bon')) { genreNum = '2'; dett = 'T'; }
            else if (genre.includes('old tantra'))                   { genreNum = '3'; dett = 'G'; }
            else if (genre.includes('bon'))                          { genreNum = '4'; dett = 'B'; }
            else                                                      { genreNum = '1'; dett = 'K'; }

            let constituent = null;
            for (const c of (entry.constituents || [])) {
                if (c.isreferenceset === '1') { constituent = c.title; break; }
            }
            if (!constituent && entry.constituents && entry.constituents.length)
                constituent = entry.constituents[0].title || null;

            // Label from COLLECTION_GROUPS
            let label = collectionId;
            for (const g of COLLECTION_GROUPS)
                for (const it of g.items)
                    if (it.id === collectionId) { label = it.label; break; }

            return { sigla, constituent, dett, genreNum, label,
                     etextDir: ETEXT_BASE + '/' + genreNum + sigla,
                     xmlUrl:   COLL_BASE + '/' + sigla + '/' + sigla + '.xml' };
        }
        return null;
    }

    /* ── volNameMap builder ─────────────────────────────────────────────────── */
    function buildVolNameMap(sigla, collections, genreNum) {
        const map = {};
        for (const entry of collections) {
            if (entry.sigla !== sigla) continue;
            // Skip entries whose genre does not match the requested genreNum
            const genre = (entry.genre || '').toLowerCase();
            let entryGenreNum;
            if (genre.includes('tanjur') && !genre.includes('bon')) entryGenreNum = '2';
            else if (genre.includes('old tantra'))                   entryGenreNum = '3';
            else if (genre.includes('bon'))                          entryGenreNum = '4';
            else                                                      entryGenreNum = '1';
            if (genreNum && entryGenreNum !== genreNum) continue;
            for (const con of (entry.constituents || [])) {
                const isRef = con.isreferenceset === '1';
                for (const vol of (con.structure || [])) {
                    if (!vol.volno || !vol.volref) continue;
                    const key = String(parseInt(vol.volno, 10)).padStart(3, '0');
                    if (isRef) {
                        map[key] = { volname: vol.volname || null, volref: vol.volref, constituent: con.title || null };
                    } else if (!map[key]) {
                        map[key] = { volname: vol.volname || null, volref: null, constituent: null };
                    }
                }
            }
            break; // first matching sigla+genre entry
        }
        return map;
    }

    /* ── Catalog XML items ──────────────────────────────────────────────────── */
    function parseCatalogXml(xmlText) {
        const doc = new DOMParser().parseFromString(xmlText, 'application/xml');
        const t = (el, tag) => { const n = el.querySelector(tag); return n ? n.textContent.trim() : ''; };
        return Array.from(doc.querySelectorAll('item')).map(item => ({
            ref:  t(item,'ref'),  rkts: t(item,'rkts'), vol:  t(item,'vol'),
            sec:  t(item,'sec'),  debp: t(item,'debp'), debf: t(item,'debf'),
            debl: t(item,'debl'), finp: t(item,'finp'), finf: t(item,'finf'),
            finl: t(item,'finl'), tib:  t(item,'tib')
        }));
    }

    /* ── Identify text from catalog items ───────────────────────────────────── */
    function identifyText(catalogItems, volref, secl, linp, linf, linl) {
        const lp = parseInt(linp, 10), ll = parseInt(linl, 10);
        for (const item of catalogItems) {
            if (item.vol !== volref) continue;
            if (item.sec !== secl)  continue;
            const ip = parseInt(item.debp, 10), il = parseInt(item.debl, 10);
            const fp = parseInt(item.finp, 10), fl = parseInt(item.finl, 10);
            const iff = item.debf, fff = item.finf;
            const inRange =
                (lp === ip && iff === 'a' && linf === 'a' && ll >= il) ||
                (lp === ip && iff === 'a' && linf === 'b') ||
                (lp === ip && iff === 'b' && linf === 'b' && ll >= il) ||
                (lp >  ip && lp < fp) ||
                (lp === fp && fff === 'b' && linf === 'b' && ll <= fl) ||
                (lp === fp && fff === 'b' && linf === 'a') ||
                (lp === fp && fff === 'a' && linf === 'a' && ll <= fl);
            if (inRange) return { ref: item.ref, rkts: item.rkts, tib: item.tib };
        }
        return null;
    }

    /* ── Parse etext file into segments ─────────────────────────────────────── */
    function parseSegments(txt) {
        const segments = [];
        for (const line of txt.split('\n')) {
            const m = line.match(/^(\S+)\(([^)]+)\):\s*(.*)$/);
            if (m) segments.push({ ref: m[1], section: m[2], content: m[3] });
        }
        return segments;
    }

    /* ── Build concatenated plain text + metadata ────────────────────────────── */
    function buildPlain(segments) {
        let plain = '';
        const charSegMap = [];  // char index → segment index
        const breakPos   = {}; // char index → next segment ref
        for (let si = 0; si < segments.length; si++) {
            const start = plain.length;
            plain += segments[si].content;
            for (let k = start; k < plain.length; k++) charSegMap[k] = si;
            if (si + 1 < segments.length) {
                charSegMap[plain.length] = si;
                breakPos[plain.length]   = segments[si + 1].ref;
                plain += ' ';
            }
        }
        return { plain, charSegMap, breakPos };
    }

    /* ── Annotate a range with folio-break sub tags ─────────────────────────── */
    function annotate(plain, breakPos, offset, length) {
        let out = '';
        for (let i = offset, end = offset + length; i < end; i++) {
            if (breakPos[i] !== undefined) {
                out += ' <sub class="text-secondary">' + esc(breakPos[i]) + '</sub> ';
            } else {
                out += esc(plain[i]);
            }
        }
        return out;
    }

    /* ── Exact cross-line search ─────────────────────────────────────────────── */
    function findExact(txt, search) {
        const segments = parseSegments(txt);
        if (!segments.length || !search) return [];
        const { plain, charSegMap, breakPos } = buildPlain(segments);
        const results = [];
        let offset = 0, idx = 0;
        while (true) {
            const pos = plain.indexOf(search, offset);
            if (pos === -1) break;
            const matchLen = search.length;
            const endPos   = pos + matchLen - 1;
            const startSI  = charSegMap[pos]    ?? 0;
            const endSI    = charSegMap[endPos] ?? startSI;
            const bFrom    = Math.max(0, pos - CTX_LEN);
            results.push({
                occurrence:  ++idx,
                split:       startSI !== endSI,
                start_ref:   segments[startSI].ref,
                end_ref:     segments[endSI].ref,
                section:     segments[startSI].section,
                before:      annotate(plain, breakPos, bFrom, pos - bFrom),
                match:       annotate(plain, breakPos, pos, matchLen),
                after:       annotate(plain, breakPos, pos + matchLen, Math.min(plain.length - pos - matchLen, CTX_LEN)),
            });
            offset = pos + 1;
        }
        return results;
    }

    /* ── Exact multi-variant search (dedup by offset, first variant wins) ────── */
    function findExactVariants(txt, searches) {
        const segments = parseSegments(txt);
        if (!segments.length) return [];
        const { plain, charSegMap, breakPos } = buildPlain(segments);
        const allResults = {}; // keyed by byte offset
        for (const search of searches) {
            if (!search) continue;
            let offset = 0;
            while (true) {
                const pos = plain.indexOf(search, offset);
                if (pos === -1) break;
                if (!allResults[pos]) {
                    const matchLen = search.length;
                    const endPos   = pos + matchLen - 1;
                    const startSI  = charSegMap[pos]    ?? 0;
                    const endSI    = charSegMap[endPos] ?? startSI;
                    const bFrom    = Math.max(0, pos - CTX_LEN);
                    allResults[pos] = {
                        split:           startSI !== endSI,
                        start_ref:       segments[startSI].ref,
                        end_ref:         segments[endSI].ref,
                        section:         segments[startSI].section,
                        before:          annotate(plain, breakPos, bFrom, pos - bFrom),
                        match:           annotate(plain, breakPos, pos, matchLen),
                        after:           annotate(plain, breakPos, pos + matchLen, Math.min(plain.length - pos - matchLen, CTX_LEN)),
                        matched_variant: search,
                    };
                }
                offset = pos + 1;
            }
        }
        const sorted = Object.keys(allResults).map(Number).sort((a,b) => a-b);
        return sorted.map((pos, i) => ({ occurrence: i+1, ...allResults[pos] }));
    }

    /* ── Approximate search (single variant) ────────────────────────────────── */
    function _approxOnce(txt, search) {
        const segments = parseSegments(txt);
        if (!segments.length || !search) return [];
        const { plain, breakPos } = buildPlain(segments);
        const plainLen = plain.length;

        // Build segStart / segEnd arrays
        const segStart = [], segEnd = [];
        let pos = 0;
        for (let si = 0; si < segments.length; si++) {
            segStart[si] = pos;
            pos += segments[si].content.length;
            segEnd[si]   = pos;
            if (si + 1 < segments.length) pos++; // space
        }

        function segmentAt(p) {
            let lo = 0, hi = segments.length - 1;
            while (lo < hi) {
                const mid = (lo + hi + 1) >> 1;
                if (segStart[mid] <= p) lo = mid; else hi = mid - 1;
            }
            return lo;
        }
        function wordsBetween(prevEnd, nextStart) {
            if (nextStart <= prevEnd) return 0;
            return (plain.slice(prevEnd, nextStart).match(/\S+/g) || []).length;
        }

        const WBL = '(?<![a-zA-Z0-9+\'\\-])', WBR = '(?![a-zA-Z0-9+\'\\-])';
        const words = search.trim().split(/\s+/).filter(Boolean);
        if (!words.length) return [];

        const oStarts = [], oEnds = [];
        for (let wi = 0; wi < words.length; wi++) {
            const re = new RegExp(WBL + words[wi].replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + WBR, 'g');
            oStarts[wi] = []; oEnds[wi] = [];
            let m;
            while ((m = re.exec(plain)) !== null) {
                oStarts[wi].push(m.index);
                oEnds[wi].push(m.index + m[0].length);
            }
        }

        function firstFrom(wi, minPos) {
            const arr = oStarts[wi]; let lo = 0, hi = arr.length;
            while (lo < hi) { const mid = (lo+hi)>>1; arr[mid] < minPos ? lo=mid+1 : hi=mid; }
            return lo;
        }

        const rawResults = [];
        function recurse(wi, prevEnd, winEnd, positions) {
            const cnt = oStarts[wi].length, from = firstFrom(wi, prevEnd);
            for (let oi = from; oi < cnt; oi++) {
                const p = oStarts[wi][oi];
                if (p >= winEnd) break;
                if (wordsBetween(prevEnd, p) > MAX_GAP) continue;
                const end = oEnds[wi][oi];
                positions[wi] = [p, end];
                if (wi + 1 === words.length) {
                    const fp = positions[0][0], le = end;
                    const startSI = segmentAt(fp), endSI = segmentAt(Math.max(0, le-1));
                    rawResults.push({
                        split: startSI !== endSI,
                        start_ref: segments[startSI].ref, end_ref: segments[endSI].ref,
                        section: segments[startSI].section,
                        _positions: [...positions], _firstPos: fp, _lastEnd: le,
                        _bFrom: Math.max(0, fp - CTX_LEN), _aTo: Math.min(plainLen, le + CTX_LEN)
                    });
                } else {
                    recurse(wi+1, end, winEnd, positions);
                }
            }
        }

        for (let oi = 0; oi < oStarts[0].length; oi++) {
            const p = oStarts[0][oi], end = oEnds[0][oi];
            const si0 = segmentAt(p);
            const winEnd = si0+1 < segments.length ? segEnd[si0+1]+1 : segEnd[si0]+1;
            const positions = [[p, end]];
            if (words.length === 1) {
                rawResults.push({
                    split: false, start_ref: segments[si0].ref, end_ref: segments[si0].ref,
                    section: segments[si0].section,
                    _positions: [...positions], _firstPos: p, _lastEnd: end,
                    _bFrom: Math.max(0, p - CTX_LEN), _aTo: Math.min(plainLen, end + CTX_LEN)
                });
            } else {
                recurse(1, end, winEnd, positions);
            }
        }

        // Render ranges with highlight spans
        function renderRange(from, to, positions) {
            const events = [];
            for (const [bp, ref] of Object.entries(breakPos)) {
                const bpN = Number(bp);
                if (bpN >= from && bpN < to) events.push({ pos: bpN, ord: 1, type: 'lb', ref });
            }
            for (const [wp, we] of positions) {
                if (wp >= from && wp < to)  events.push({ pos: wp, ord: 2, type: 'open',  ref: null });
                if (we >  from && we <= to) events.push({ pos: we, ord: 0, type: 'close', ref: null });
            }
            events.sort((a,b) => a.pos-b.pos || a.ord-b.ord);
            let out = '', cur = from;
            for (const ev of events) {
                if (ev.pos > cur) out += esc(plain.slice(cur, ev.pos));
                cur = ev.pos;
                if (ev.type === 'lb')    { out += ' <sub class="text-secondary">' + esc(ev.ref) + '</sub> '; cur++; }
                else if (ev.type === 'open')  out += '<span class="text-danger fw-bold">';
                else if (ev.type === 'close') out += '</span>';
            }
            if (cur < to) out += esc(plain.slice(cur, to));
            return out;
        }

        return rawResults.map((r, i) => ({
            occurrence: i+1,
            split: r.split, start_ref: r.start_ref, end_ref: r.end_ref, section: r.section,
            _firstPos: r._firstPos,
            before: renderRange(r._bFrom,    r._firstPos, []),
            match:  renderRange(r._firstPos, r._lastEnd,  r._positions),
            after:  renderRange(r._lastEnd,  r._aTo,      []),
        }));
    }

    /* ── Approximate multi-variant (binome-aware, dedup by _firstPos) ────────── */
    function findApproxVariants(txt, search, binomes) {
        const variants = getSearchVariants(search, binomes);
        const allByPos = {};
        for (const variant of variants) {
            for (const r of _approxOnce(txt, variant)) {
                if (!allByPos[r._firstPos]) {
                    allByPos[r._firstPos] = { ...r, matched_variant: variant };
                }
            }
        }
        const sorted = Object.keys(allByPos).map(Number).sort((a,b) => a-b);
        return sorted.map((pos, i) => {
            const r = { ...allByPos[pos] };
            delete r._firstPos;
            r.occurrence = i+1;
            return r;
        });
    }

    /* ── Binome variant expansion ────────────────────────────────────────────── */
    function getSearchVariants(search, binomes) {
        let variants = [search];
        let changed = true;
        while (changed) {
            changed = false;
            const newVariants = [];
            for (const v of variants) {
                for (const [a, b] of binomes) {
                    for (const [from, to] of [[a,b],[b,a]]) {
                        if (v.includes(from)) {
                            const replaced = v.split(from).join(to);
                            if (!variants.includes(replaced) && !newVariants.includes(replaced)) {
                                newVariants.push(replaced);
                                changed = true;
                            }
                        }
                    }
                }
            }
            variants = [...variants, ...newVariants];
        }
        return [...new Set(variants)];
    }

    /* ── Parse binomes textarea ──────────────────────────────────────────────── */
    function parseBinomes(raw) {
        const binomes = [];
        for (const line of raw.replace(/\r\n/g, '\n').split('\n')) {
            const l = line.trim();
            if (!l || !l.includes('=')) continue;
            const eq = l.indexOf('=');
            const a = l.slice(0, eq).trim(), b = l.slice(eq+1).trim();
            if (a && b) binomes.push([a, b]);
        }
        return binomes;
    }

    /* ── Render one match ────────────────────────────────────────────────────── */
    function renderMatch(m, volref, volNameMap, catalogItems, cfg, mode, originalText) {
        const loc      = m.start_ref + (m.split ? ' → ' + m.end_ref : '');
        const secl     = m.section;
        const lin      = loc.split(' ')[0];

        let linp = lin, linf = 'a', linl = '1';
        const posA = lin.indexOf('a'), posB = lin.indexOf('b');
        let pos = -1;
        if (posB !== -1) pos = posB;
        if (posA !== -1) pos = posA; // 'a' wins
        if (pos !== -1) { linp = lin.slice(0, pos); linf = lin[pos]; linl = lin.slice(pos+1); }

        const volEntry  = volNameMap[volref] || null;
        const volName   = volEntry?.volname  ?? null;
        const bdrcVolref= volEntry?.volref   ?? volref;
        const volConstit= volEntry?.constituent ?? cfg.constituent;

        const textRef  = catalogItems.length ? identifyText(catalogItems, bdrcVolref, secl, linp, linf, linl) : null;
        const pageRef  = linp + linf;

        let imgHtml = '';
        if (volConstit && bdrcVolref) {
            const p = new URLSearchParams({
                title: volConstit, coll: cfg.sigla,
                vol: bdrcVolref, sec: secl, page: pageRef,
                rkts: textRef ? (cfg.dett + textRef.rkts) : ''
            });
            imgHtml = ` <a href="images.html?${p}" target="_blank" class="ms-1">🕮 view images</a>`;
        }

        let variantHtml = '';
        const matched = m.matched_variant;
        if (matched && matched !== originalText) {
            variantHtml = ` <span class="badge bg-warning text-dark ms-1" title="Matched via interchangeable spelling">${esc(matched)}</span>`;
        }

        let html = '<div class="rkts-result mb-3">';
        if (textRef) {
            const catUrl     = 'detail.html?id=' + encodeURIComponent(cfg.dett + textRef.rkts);
            const etextUrl   = 'etext_complete.html?id=' + encodeURIComponent(textRef.ref);
            html += `<div class="rkts-result-header">
                <a href="${esc(catUrl)}" target="_blank" class="fw-bold text-decoration-none me-1">${esc(textRef.ref)}</a>
                <span class="script-text me-1" data-lang="tib" data-original="${esc(textRef.tib)}">${esc(textRef.tib)}</span>
                <a href="${esc(catUrl)}" target="_blank" title="Open catalogue entry"><i class="bi bi-box-arrow-up-right small text-muted"></i></a>
                <a href="${esc(etextUrl)}" target="_blank" class="ms-2">🕮 view complete e-text</a>
                <span class="text-muted small ms-3">Vol. ${esc(volref)}${volName ? ` <span class="fst-italic">(${esc(volName)})</span>` : ''}</span>
            </div>`;
        } else {
            html += `<div class="rkts-result-header text-muted">Vol. ${esc(volref)}${volName ? ` <span class="fst-italic">(${esc(volName)})</span>` : ''}</div>`;
        }

        const matchHtml = mode === 'exact'
            ? `<span class="text-danger fw-bold">${m.match}</span>`
            : m.match;

        html += `<div class="rkts-result-loc">
            <code>${esc(loc)}</code> <span class="text-muted fst-italic">(${esc(secl)})</span>
            ${imgHtml}${variantHtml}
        </div>
        <div class="rkts-result-snippet">…<span class="script-text" data-lang="tib"
            data-before="${esc(m.before)}" data-match="${esc(m.match)}" data-after="${esc(m.after)}"
        >${m.before}${matchHtml}${m.after}</span>…</div>`;
        html += '</div>';
        return html;
    }

    /* ── Collection select population ───────────────────────────────────────── */
    function populateSelect(expert) {
        const sel = document.getElementById('collection-select');
        const current = sel.value;
        sel.innerHTML = '';
        for (const g of COLLECTION_GROUPS) {
            const optGrp = document.createElement('optgroup');
            optGrp.label = g.group;
            for (const it of g.items) {
                if (it.expert && !expert) continue;
                const opt = document.createElement('option');
                opt.value = it.id;
                opt.textContent = it.label;
                if (it.id === current || (!current && it.id === 'D')) opt.selected = true;
                optGrp.appendChild(opt);
            }
            if (optGrp.children.length) sel.appendChild(optGrp);
        }
        if (!sel.value) sel.value = 'D';
    }

    /* ── URL state sync ──────────────────────────────────────────────────────── */
    function getParam(name) {
        return new URLSearchParams(window.location.search).get(name) || '';
    }
    function updateUrl(params) {
        const url = new URL(window.location);
        for (const [k,v] of Object.entries(params)) {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
            else url.searchParams.delete(k);
        }
        history.replaceState(null, '', url);
    }

    /* ── Tibetan toggle ──────────────────────────────────────────────────────── */
    window.tibGlobalMode = 'ewts';

    function convertTibHtml(html, mode) {
        if (mode !== 'unicode') return html;
        const tmp = document.createElement('span');
        tmp.innerHTML = html;
        (function walk(node) {
            node.childNodes.forEach(child => {
                if (child.nodeType === 3) child.nodeValue = toUnicode(child.nodeValue);
                else walk(child);
            });
        })(tmp);
        return tmp.innerHTML;
    }

    function applyTibMode(mode) {
        document.querySelectorAll('.script-text[data-lang="tib"]').forEach(el => {
            if (el.dataset.before !== undefined) {
                const b = convertTibHtml(el.dataset.before || '', mode);
                const m = convertTibHtml(el.dataset.match  || '', mode);
                const a = convertTibHtml(el.dataset.after  || '', mode);
                el.innerHTML = b + '<span class="text-danger fw-bold">' + m + '</span>' + a;
                el.classList.toggle('tib', mode === 'unicode');
            } else {
                const original = el.dataset.original || el.textContent;
                el.textContent = mode === 'unicode' ? toUnicode(original) : original;
                el.classList.toggle('tib', mode === 'unicode');
            }
        });
        const btn = document.getElementById('global-tib-toggle');
        if (btn) {
            btn.dataset.mode = mode;
            btn.title = mode === 'unicode' ? 'Show in Wylie (EWTS)' : 'Show in Tibetan script';
            btn.innerHTML = mode === 'unicode' ? '<span class="toggle-label">Abc</span>' : '<i class="bi bi-translate"></i>';
        }
    }

    document.addEventListener('click', e => {
        if (e.target.closest('.tib-toggle-btn')) {
            window.tibGlobalMode = window.tibGlobalMode === 'ewts' ? 'unicode' : 'ewts';
            applyTibMode(window.tibGlobalMode);
        }
    });

    /* ── Main search ─────────────────────────────────────────────────────────── */
    async function runSearch() {
        const textInput = document.getElementById('text-input').value.trim();
        const collection = document.getElementById('collection-select').value;
        const mode = document.querySelector('input[name="mode"]:checked')?.value || 'exact';
        const expert = document.querySelector('input[name="expert"]:checked')?.value === '1';
        const binomesRaw = IS_2X ? (document.getElementById('binomes-input')?.value || '') : '';
        const binomes = IS_2X ? parseBinomes(binomesRaw) : [];
        const searchVariants = IS_2X && textInput ? getSearchVariants(textInput, binomes) : [textInput];
        const hasVariants = searchVariants.length > 1;
        const originalText = textInput;

        // Update cross-link
        const crossBase = IS_2X ? 'results2.html' : 'results2x.html';
        const crossP = new URLSearchParams({ collection, text: textInput, mode, expert: expert?'1':'0' });
        if (IS_2X && binomesRaw) crossP.set('binomes', binomesRaw);
        const crossLink = document.getElementById(IS_2X ? 'link-to-2' : 'link-to-2x');
        if (crossLink) crossLink.href = crossBase + '?' + crossP;
        const btnCross  = document.getElementById(IS_2X ? 'btn-standard' : 'btn-customizable');
        if (btnCross)  btnCross.href = crossBase + '?' + crossP;

        updateUrl({ collection, text: textInput, mode, expert: expert?'1':'0',
                    ...(IS_2X && binomesRaw ? { binomes: binomesRaw } : {}) });

        const resultsArea = document.getElementById('results-area');

        if (!textInput) {
            resultsArea.innerHTML = '<p class="text-muted fst-italic">Enter a search phrase above to search the e-text corpus.<br><small>Initial searches may take a moment.</small></p>';
            setProgress(false);
            return;
        }

        // Update variants badge (results2x only)
        if (IS_2X) {
            const badge = document.getElementById('variants-badge');
            const listWrap = document.getElementById('variants-list-wrap');
            const listEl   = document.getElementById('variants-list');
            if (badge && hasVariants) {
                badge.textContent = searchVariants.length + ' variants active';
                badge.classList.remove('d-none');
                listEl.innerHTML = searchVariants.map(v => `<span class="badge bg-light text-dark border me-1">${esc(v)}</span>`).join('');
                listWrap.classList.remove('d-none');
            } else if (badge) {
                badge.classList.add('d-none');
                if (listWrap) listWrap.classList.add('d-none');
            }
        }

        setProgress(true, 'Loading collections…');
        resultsArea.innerHTML = '';

        let collections;
        try {
            const r = await fetch(COLLS_URL);
            collections = r.ok ? await r.json() : [];
        } catch { collections = []; }

        const cfg = getCollCfg(collection, collections) || getCollCfg('D', collections);
        if (!cfg) { resultsArea.innerHTML = '<div class="alert alert-danger">Unknown collection.</div>'; setProgress(false); return; }

        const volNameMap = buildVolNameMap(cfg.sigla, collections, cfg.genreNum);

        // Load catalog XML
        setProgress(true, 'Loading catalog…');
        let catalogItems = [];
        try {
            const r = await fetch(cfg.xmlUrl);
            if (r.ok) catalogItems = parseCatalogXml(await r.text());
        } catch {}

        // Discover etext files: volNameMap keys give zero-padded volnos
        const volKeys = Object.keys(volNameMap).sort();
        if (!volKeys.length) {
            resultsArea.innerHTML = `<div class="alert alert-secondary">No volume data available for <em>${esc(cfg.label)}</em>.</div>`;
            setProgress(false);
            return;
        }

        const searchResults = {}; // volKey → matches[]
        let totalMatches = 0;

        for (let i = 0; i < volKeys.length; i++) {
            const volKey = volKeys[i];
            setProgress(true, `Searching vol. ${parseInt(volKey,10)} (${i+1}/${volKeys.length})…`, (i+1)/volKeys.length*100);
            // Yield to browser
            await new Promise(r => setTimeout(r, 0));

            const url = cfg.etextDir + '/' + volKey + '.txt';
            let txt;
            try {
                const r = await fetch(url);
                if (!r.ok) continue;
                txt = await r.text();
            } catch { continue; }

            let matches;
            if (mode === 'exact') {
                matches = IS_2X ? findExactVariants(txt, searchVariants) : findExact(txt, textInput);
            } else {
                matches = findApproxVariants(txt, textInput, IS_2X ? binomes : []);
            }
            if (matches.length) {
                searchResults[volKey] = matches;
                totalMatches += matches.length;
            }
        }

        setProgress(false);
        renderResults(searchResults, totalMatches, textInput, originalText, cfg, mode,
                      volNameMap, catalogItems, searchVariants, hasVariants,
                      collection, expert, binomesRaw);
    }

    /* ── Render full results ─────────────────────────────────────────────────── */
    function renderResults(searchResults, totalMatches, text, originalText, cfg, mode,
                           volNameMap, catalogItems, searchVariants, hasVariants,
                           collection, expert, binomesRaw) {
        const resultsArea  = document.getElementById('results-area');
        const modeLabel    = mode === 'exact' ? 'exact' : 'approximate';
        const altMode      = mode === 'exact' ? 'approx' : 'exact';
        const altModeLabel = mode === 'exact' ? 'approximate' : 'exact';
        const target       = IS_2X ? 'results2x.html' : 'results2.html';

        function altUrl(overrideMode) {
            const p = new URLSearchParams({ collection, text, mode: overrideMode, expert: expert?'1':'0' });
            if (IS_2X && binomesRaw) p.set('binomes', binomesRaw);
            return target + '?' + p;
        }

        if (totalMatches === 0) {
            let html = `<div class="alert alert-secondary">No ${modeLabel} matches found for
                <strong>${esc(text)}</strong>`;
            if (IS_2X && hasVariants)
                html += ` (or any of its ${searchVariants.length-1} spelling variant${searchVariants.length-1!==1?'s':''})`;
            html += ` in <em>${esc(cfg.label)}</em>.
                <a href="${esc(altUrl(altMode))}">Try ${altModeLabel} search?</a></div>`;
            resultsArea.innerHTML = html;
            return;
        }

        const volCount = Object.keys(searchResults).length;
        let html = `<div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong>${totalMatches}</strong> ${modeLabel} match${totalMatches!==1?'es':''}
                for <span class="text-danger fw-bold">${esc(text)}</span>`;
        if (IS_2X && hasVariants)
            html += ` <span class="text-muted small">(+${searchVariants.length-1} variant${searchVariants.length-1!==1?'s':''})</span>`;
        html += ` in <em>${esc(cfg.label)}</em>
                across <strong>${volCount}</strong> volume${volCount!==1?'s':''}.
            </div>
            <div class="text-muted small">
                <a href="${esc(altUrl(altMode))}">Also try ${altModeLabel} search</a>
            </div>
        </div>`;

        for (const [volKey, matches] of Object.entries(searchResults).sort((a, b) => parseInt(a[0], 10) - parseInt(b[0], 10))) {
            const volEntry = volNameMap[volKey] || {};
            const volName  = volEntry.volname || null;
            html += `<div class="volume-block">
                <div class="volume-title">
                    <i class="bi bi-book text-muted me-1"></i>
                    Vol. ${esc(volKey)}
                    ${volName ? `<span class="fst-italic text-secondary">– ${esc(volName)}</span>` : ''}
                    <span class="text-muted fw-normal ms-1">(${matches.length} match${matches.length!==1?'es':''})</span>
                </div>`;
            for (const m of matches) {
                html += renderMatch(m, volKey, volNameMap, catalogItems, cfg, mode, originalText);
            }
            html += '</div>';
        }

        resultsArea.innerHTML = html;
        if (window.tibGlobalMode === 'unicode') applyTibMode('unicode');
    }

    /* ── Progress UI ─────────────────────────────────────────────────────────── */
    function setProgress(active, msg, pct) {
        const area = document.getElementById('progress-area');
        const msgEl = document.getElementById('progress-msg');
        const bar   = document.getElementById('progress-bar');
        area.style.display = active ? '' : 'none';
        if (msg && msgEl) msgEl.textContent = msg;
        if (bar) bar.style.width = (pct || 0) + '%';
    }

    /* ── Init ────────────────────────────────────────────────────────────────── */
    window.addEventListener('DOMContentLoaded', () => {
        // Populate collection select with selective (non-expert) options initially
        populateSelect(false);

        // Read URL params
        const p = new URLSearchParams(window.location.search);
        const initCollection = p.get('collection') || 'D';
        const initText       = p.get('text')       || '';
        const initMode       = p.get('mode')       || 'exact';
        const initExpert     = p.get('expert')     === '1';
        const initBinomes    = IS_2X ? (p.get('binomes') || '') : '';

        // Set expert radio first, then re-populate select
        if (initExpert) {
            document.getElementById('expSearch').checked = true;
            populateSelect(true);
        }

        // Set collection
        const sel = document.getElementById('collection-select');
        if (initCollection) sel.value = initCollection;
        if (!sel.value) sel.value = 'D';

        // Set text
        document.getElementById('text-input').value = initText;

        // Set mode
        const modeEl = document.getElementById(initMode === 'approx' ? 'modeApprox' : 'modeExact');
        if (modeEl) modeEl.checked = true;

        // Set binomes (results2x)
        if (IS_2X && initBinomes) {
            const ta = document.getElementById('binomes-input');
            if (ta) ta.value = initBinomes;
            const collapse = document.getElementById('binomesCollapse');
            if (collapse) collapse.classList.add('show');
            const caret = document.getElementById('binomes-caret');
            if (caret) caret.className = 'bi bi-caret-up-fill';
        }

        // Caret toggle
        const collapseEl = document.getElementById('binomesCollapse');
        if (collapseEl) {
            collapseEl.addEventListener('show.bs.collapse', () => {
                const c = document.getElementById('binomes-caret');
                if (c) c.className = 'bi bi-caret-up-fill';
            });
            collapseEl.addEventListener('hide.bs.collapse', () => {
                const c = document.getElementById('binomes-caret');
                if (c) c.className = 'bi bi-caret-down-fill';
            });
        }

        // Expert toggle rebuilds select
        document.querySelectorAll('input[name="expert"]').forEach(radio => {
            radio.addEventListener('change', () => {
                populateSelect(radio.value === '1');
                const cur = initCollection;
                const sel = document.getElementById('collection-select');
                if ([...sel.options].some(o => o.value === cur)) sel.value = cur;
            });
        });

        // Search button
        document.getElementById('search-btn').addEventListener('click', runSearch);
        document.getElementById('text-input').addEventListener('keydown', e => {
            if (e.key === 'Enter') runSearch();
        });

        // Run on load if params present
        if (initText) runSearch();
    });
})();