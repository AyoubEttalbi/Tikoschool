<?php

use App\Support\ArabicPdfText;

/*
 * ArabicPdfText is the seam between names stored in MySQL and names dompdf can print.
 * dompdf has no Arabic shaping and no bidi algorithm — raw Arabic prints as isolated,
 * left-to-right letters, the "عدجلا كرتش" gibberish the client reported on every
 * level roster, the absence sheet and the factures. These tests pin the contract the
 * PDF views rely on:
 *
 *   - Arabic in, presentation-form codepoints (U+FE70..U+FEFF) out: the only thing
 *     DejaVu Sans can draw as connected, right-to-left Arabic.
 *   - Latin digits stay Latin ("1 AC" never becomes "١ AC") — level names are
 *     French-first.
 *   - Pure-French strings come back byte-for-byte unchanged, so the guard is what
 *     keeps the 99%-French documents from paying for the shaper.
 *   - Null/empty never explode a blade render.
 */

it('leaves French-only text byte-for-byte unchanged', function () {
    foreach (['2 BAC', 'Classe Démo G1', 'PRIMAIRE GR 2 (PRIVE)', 'Élève é à ç — été'] as $french) {
        expect(ArabicPdfText::shape($french))->toBe($french);
    }
});

it('returns an empty string for null and empty input', function () {
    expect(ArabicPdfText::shape(null))->toBe('')
        ->and(ArabicPdfText::shape(''))->toBe('');
});

it('converts Arabic letters into presentation-form codepoints', function () {
    $shaped = ArabicPdfText::shape('الأولى إعدادي');

    // The whole point: dompdf can only print Arabic as U+FE70..U+FEFF presentation
    // forms. If the raw block letters survive shaping, the PDF garbles — this is the
    // exact regression that shipped to the client.
    $codepoints = array_map(
        fn ($char) => mb_ord($char, 'UTF-8'),
        mb_str_split($shaped, 1, 'UTF-8')
    );

    expect($codepoints)->not->toBeEmpty();

    // Letters that JOIN must come out as presentation forms. Ar-PHP legitimately
    // leaves a non-joining letter (waw, alef: isolated even mid-word in real Arabic)
    // as its base codepoint — DejaVu carries the identical isolated glyph for those
    // (U+FE8D/U+FE8E), so a lone base letter is not evidence of a shaping failure,
    // but a JOINING letter left in the base block is.
    // م joins; leaving it unshaped would print it disconnected.
    $joining = array_filter($codepoints, fn ($cp) => $cp === mb_ord('م', 'UTF-8'));
    $presentationForms = array_filter(
        $codepoints,
        fn ($cp) => $cp >= 0xFE70 && $cp <= 0xFEFF
    );

    expect($joining)->toBeEmpty()
        ->and(count($presentationForms))->toBeGreaterThan(0);
});

it('shapes mixed French/Arabic without touching the Latin part or its digits', function () {
    $name = '1 AC - الأولى إعدادي';
    $shaped = ArabicPdfText::shape($name);

    expect($shaped)
        ->toContain('1 AC -')          // Latin prefix, order and digits intact
        ->not->toBe($name);            // ...but the Arabic half is transformed

    // Hindu digit '١' (U+0661) must never replace the Latin '1'.
    expect(mb_strpos($shaped, "\u{0661}", 0, 'UTF-8'))->toBeFalse();
});

it('does not hard-wrap names longer than the shaper\'s default line length', function () {
    // utf8Glyphs' $max_chars exists for body text; at the default 50 it inserts newlines
    // mid-name, which would let the shaper — not the cell layout — decide where a long
    // Arabic school name folds. The helper passes a bigger budget.
    $long = 'مركز تيكو سكول للدروس والدعم - القسم الأول الثانوي إعدادي بالمملكة المغربية';

    expect(mb_strlen($long, 'UTF-8'))->toBeGreaterThan(50)
        ->and(ArabicPdfText::shape($long))->not->toContain("\n");
});

it('round-trips through blade escaping unchanged for the PDF to render', function () {
    // The views print shaped text through {{ }}, which runs htmlspecialchars. Entities
    // decode back to the same codepoints inside dompdf, so what must hold is that
    // escaping does not corrupt or reorder the shaped glyphs — not that & < > are
    // absent (a Latin "&" inside a mixed name legitimately survives shaping).
    $shaped = ArabicPdfText::shape('قسم النخبة (G1) الأولى');

    $escaped = htmlspecialchars($shaped, ENT_QUOTES, 'UTF-8', true);
    $unescaped = html_entity_decode($escaped, ENT_QUOTES, 'UTF-8');

    expect($unescaped)->toBe($shaped);
});
