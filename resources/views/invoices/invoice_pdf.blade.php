{{--
    The learner invoice, in the client's design.

    Rebuilt from their example PDF ("Bill-hiba EL BOUZIRI 2026-07.pdf"): one A4 sheet
    carrying the SAME receipt twice — the school's copy and the learner's, separated by
    a cut line. The copies are two titles of the same paper: the school's "FACTURE" and
    the learner's "REÇU". In their example the school copy carries the pack price and
    the learner copy does not; that asymmetry is kept on purpose.

    Measurements are the example's, not invented: DejaVu Sans 12pt rows, bold 14pt title
    at the top right, near-white row fill with #DDD row rules inside a #CCC box, label
    column ~31% of the width. DejaVu because the core PDF fonts cannot print "é".
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture</title>
    <style>
        @page { margin: 28px 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 12px; }

        .copy { page-break-inside: avoid; }

        .copy-header { width: 100%; margin-bottom: 14px; }
        .copy-header td { vertical-align: top; }
        .logo { height: 56px; max-width: 190px; }
        .brand-tagline { font-size: 9px; color: #555; margin-top: 2px; }
        .doc-title { font-size: 14px; font-weight: bold; text-align: right; }

        table.fields { width: 100%; border-collapse: collapse; border: 1px solid #CCC; }
        table.fields td { padding: 6px 8px; font-size: 12px; background-color: #FEFEFE; border-bottom: 1px solid #D5D5D5; vertical-align: top; }
        table.fields tr.last td { border-bottom: none; }
        td.label { width: 31%; }
        td.value { width: 69%; }

        .cut { border-top: 1px dashed #BBB; margin: 26px 0; }

        .amount { font-weight: bold; }
    </style>
</head>
<body>

@php
    /*
     * Subjects straight from the membership's teachers array — the same source every
     * other surface reads. Distinct, because one teacher appearing twice on the JSON
     * would otherwise print twice for a parent counting lines.
     */
    $teachers = is_array($membership?->teachers) ? $membership->teachers : json_decode((string) $membership?->teachers, true);
    $subjects = collect($teachers ?? [])->pluck('subject')->filter()->unique()->values();
@endphp

{{-- The school copy first — it is the one that carries the pack price. --}}
@foreach([[true, 'Copie école'], [false, 'Copie élève']] as [$isSchoolCopy, $copyName])

    <div class="copy">
        <table class="copy-header">
            <tr>
                <td style="width: 60%;">
                    <img src="{{ public_path('logo-tiko-horizontal.png') }}" class="logo" alt="Logo">
                    {{-- BRAND from config, never the branch — same rule as every document. --}}
                    <div class="brand-tagline">{{ config('school.name') }} — votre guide du succès</div>
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="doc-title">{{ $isSchoolCopy ? 'FACTURE' : 'REÇU' }}</div>
                    <div class="brand-tagline">{{ $copyName }} · N° {{ $invoice->id }}</div>
                </td>
            </tr>
        </table>

        <table class="fields">
            <tr>
                <td class="label">Date de création :</td>
                <td class="value">{{ $invoice->creationDate?->format('d/m/Y | H:i') }}</td>
            </tr>
            <tr>
                <td class="label">Élève :</td>
                <td class="value">{{ $student?->firstName }} {{ $student?->lastName }}</td>
            </tr>
            <tr>
                <td class="label">Niveau :</td>
                <td class="value">{{ $student?->level?->name ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">Date de facturation :</td>
                <td class="value">{{ $invoice->billDate?->format('Y-m') }}</td>
            </tr>
            @if ($isSchoolCopy)
                <tr>
                    <td class="label">Prix du pack :</td>
                    <td class="value"><span class="amount">{{ number_format((float) $invoice->totalAmount, 2, ',', ' ') }} DH</span></td>
                </tr>
            @endif
            <tr>
                <td class="label">Montant payé :</td>
                <td class="value"><span class="amount">{{ number_format((float) $invoice->amountPaid, 2, ',', ' ') }} DH</span></td>
            </tr>
            <tr>
                <td class="label">Offre :</td>
                <td class="value">{{ $offerName }}</td>
            </tr>
            <tr class="last">
                <td class="label">Matières :</td>
                <td class="value">
                    @forelse ($subjects as $subject)
                        • {{ $subject }}@if(! $loop->last)  @endif
                    @empty
                        —
                    @endforelse
                </td>
            </tr>
        </table>
    </div>

    @if ($loop->first)
        <div class="cut"></div>
    @endif
@endforeach

</body>
</html>
