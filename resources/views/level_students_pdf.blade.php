{{--
    Level roster: every active student in one level, with what they pay for and whether
    they have paid it.

    Styled to match absence_list_pdf so the two documents read as one family — same header
    block, same red rule, same ✓ / ✗ vocabulary for paid and assured. DejaVu Sans is not
    decorative: the core PDF fonts carry neither the check/cross glyphs nor "é", and the
    substitution is silent, so a different family here would print boxes.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Liste des élèves — {{ $level->name }}</title>
    <style>
        @page { margin: 18px 22px; }
        body { font-family: DejaVu Sans, sans-serif; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .logo { height: 70px; }
        .school-title { font-size: 1.3em; font-weight: bold; text-align: right; }
        .school-slogan { font-size: 0.95em; color: #444; text-align: right; }
        .red-line { border-top: 2px solid #a00; margin: 8px 0 6px 0; }
        .info-row { width: 100%; font-size: 1em; font-weight: bold; margin-bottom: 6px; }
        .info-row td { padding: 2px 6px; }

        table.roster { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.roster th, table.roster td { border: 1px solid #bbb; padding: 3px 4px; text-align: center; }
        table.roster th { background: #f8f8f8; font-weight: bold; }
        table.roster td.name, table.roster th.name { text-align: left; min-width: 150px; }
        table.roster td.offer, table.roster th.offer { text-align: left; min-width: 130px; }
        {{-- Keeps a pupil's row whole across a page break, which matters because a row can
             carry several stacked membership lines. Measured on the real 110-pupil level:
             4.2 s with it, 3.9 s without, identical peak memory. dompdf can be pathological
             about this rule on long tables, so if a much larger roster ever renders slowly,
             this is the first thing to try removing. --}}
        table.roster tr { page-break-inside: avoid; }

        .line { display: block; }
        .st-paid { color: #1a7f37; font-weight: bold; }
        .st-pending { color: #e67e22; font-weight: bold; }
        .st-expired { color: #a00; font-weight: bold; }
        .muted { color: #bbb; }
        .assurance-paid { color: #1a7f37; font-weight: bold; font-size: 13px; }
        .assurance-unpaid { color: #a00; font-weight: bold; font-size: 13px; }
        .foot { margin-top: 10px; font-size: 9px; color: #666; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 30%;">
                <img src="{{ public_path('logo.png') }}" class="logo" alt="Logo">
            </td>
            <td style="width: 70%; text-align: right;">
                {{-- Always the brand, never the branch: "Tiko School C1" is a site, the
                     school is Tiko School. The branch is named in the info row below. --}}
                <div class="school-title">{{ config('school.name') }}</div>
                <div class="school-slogan">votre guide du succès</div>
            </td>
        </tr>
    </table>
    <div class="red-line"></div>

    <table class="info-row">
        <tr>
            <td style="text-align:left;">Liste des élèves</td>
            <td style="text-align:center;">Niveau : {{ $level->name }}</td>
            <td style="text-align:center;">
                Établissement : {{ $school->name ?? 'Tous' }}
            </td>
            <td style="text-align:right;">{{ $generatedAt->format('d/m/Y') }}</td>
        </tr>
    </table>

    <table class="roster">
        <thead>
            <tr>
                <th style="width: 26px;">N°</th>
                <th class="name">Nom complet</th>
                <th class="offer">Offre</th>
                <th style="width: 90px;">Statut</th>
                <th style="width: 60px;">Assurance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $i => $student)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="name">{{ strtoupper($student->lastName) }} {{ $student->firstName }}</td>

                    {{-- One student can hold several memberships — Maths with one teacher,
                         Physics with another. Both cells stack their lines in the same
                         order so row N of "Offre" lines up with row N of "Statut". --}}
                    <td class="offer">
                        @forelse($student->memberships as $membership)
                            <span class="line">{{ optional($membership->offer)->offer_name ?? 'Offre supprimée' }}</span>
                        @empty
                            <span class="muted">—</span>
                        @endforelse
                    </td>
                    <td>
                        @forelse($student->memberships as $membership)
                            @php
                                // The column is enum('pending','paid','expired'); anything
                                // else means the enum grew and this needs a new branch,
                                // so it is shown raw rather than silently coloured wrong.
                                $class = match ($membership->payment_status) {
                                    'paid' => 'st-paid',
                                    'pending' => 'st-pending',
                                    'expired' => 'st-expired',
                                    default => 'muted',
                                };
                                $label = match ($membership->payment_status) {
                                    'paid' => 'Payé',
                                    'pending' => 'En attente',
                                    'expired' => 'Expiré',
                                    default => (string) $membership->payment_status,
                                };
                            @endphp
                            <span class="line {{ $class }}">{{ $label }}</span>
                        @empty
                            <span class="muted">—</span>
                        @endforelse
                    </td>

                    <td>
                        @if($student->assurance)
                            <span class="assurance-paid">&#10003;</span>
                        @else
                            <span class="assurance-unpaid">&#10007;</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="padding: 18px; color:#666;">
                        Aucun élève actif dans ce niveau.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">
        {{ $students->count() }} élève{{ $students->count() > 1 ? 's' : '' }} actif{{ $students->count() > 1 ? 's' : '' }}
        — édité le {{ $generatedAt->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
