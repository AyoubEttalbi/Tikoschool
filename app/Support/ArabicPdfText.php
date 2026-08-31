<?php

namespace App\Support;

use ArPHP\I18N\Arabic;

/*
 * Arabic text as dompdf is able to print it.
 *
 * THE PROBLEM
 * -----------
 * A level named "1 AC - الأولى إعدادي" is stored perfectly in MySQL but prints as
 * disconnected, left-to-right gibberish (the reversed-looking "عدجلا كرتش" the client
 * reported), because correct Arabic needs two things a browser does for free and dompdf
 * does not do AT ALL (its own source carries "FIXME RTL" comments):
 *
 *   1. SHAPING  — Arabic letters join into contextual initial/medial/final forms plus
 *      ligatures (lam-alef). dompdf maps every codepoint to one isolated glyph.
 *   2. BIDI     — the Arabic run must be laid out right-to-left inside the otherwise
 *      French, left-to-right line. dompdf lays everything out left-to-right.
 *
 * THE FIX
 * -------
 * Shape the string BEFORE dompdf sees it. Ar-PHP's utf8Glyphs() converts Arabic letters
 * to their Unicode presentation forms (U+FE70..U+FEFF) and reorders runs into visual
 * order — and leaves Latin text (and, with $hindo=false, Latin digits) untouched. dompdf
 * then draws those codepoints verbatim; nothing in the engine has to understand Arabic.
 *
 * The bundled DejaVu Sans carries the presentation-form glyphs in both regular and
 * bold (161 uniFE* entries each, including the lam-alef ligature U+FEFC), so no new
 * font is required. The teacher-invoice views print Arial (a core PDF font with no
 * Arabic) — those views add DejaVu Sans as a CSS fallback, and dompdf's per-character
 * font mapping (FontMetrics::mapTextToFonts) switches runs automatically.
 *
 * WHY A CLASS WITH A STATIC, NOT A BLADE FUNCTION
 * ----------------------------------------------
 * The shaper object is stateful; sharing one instance would leak shaping state across
 * requests in a worker. A static with a fresh instance per call is immune to that, and
 * the guard (see below) means the ~100% French case — most documents in a term — never
 * pays for instantiating it.
 *
 * ESCAPING
 * --------
 * Shaped output contains no HTML-significant bytes (verified: no & < > in the
 * presentation-form range), so `{{ ArabicPdfText::shape($level->name) }}` is safe —
 * Blade escapes, nothing is corrupted, and dompdf renders the entities back to the
 * same codepoints.
 */

class ArabicPdfText
{
    /** Any Arabic-block codepoint means the string needs shaping. Presentation forms
     * (FE70+) never appear in stored data — they are what shape() emits. */
    private const ARABIC_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u';

    /**
     * Names, offers, school names — never a paragraph. The wrap-length parameter exists
     * for body text; feeding it a real name would hard-wrap at 50 chars and let the
     * shaper's own line-breaking (not dompdf's, which never sees the original string)
     * decide where a long name folds. 500 is effectively "never": longer than any
     * cell content in these documents while still bounding a pathological input.
     */
    private const NO_WRAP_LENGTH = 500;

    /**
     * A string dompdf renders correctly: shaped and bidi-reordered if it contains
     * Arabic, byte-for-byte unchanged if it does not.
     */
    public static function shape(?string $text): string
    {
        $text = (string) $text;

        // The overwhelmingly common case. Also keeps Ar-PHP's constructor (which parses
        // its glyph tables) off the hot path for documents that are entirely French.
        if ($text === '' || preg_match(self::ARABIC_PATTERN, $text) !== 1) {
            return $text;
        }

        $arabic = new Arabic('Glyphs');

        // $hindo=false: keep Latin digits. "1 AC" must not become "١ AC" — the client's
        // level names are French-first and print in French documents.
        // $forcertl=false: a mixed string keeps its Latin base direction, which matches
        // every document here (labels are French: "Niveau : ...", "Classe : ...").
        return $arabic->utf8Glyphs($text, self::NO_WRAP_LENGTH, false, false);
    }
}
