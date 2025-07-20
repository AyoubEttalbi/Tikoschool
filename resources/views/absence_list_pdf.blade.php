<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liste de présence</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .logo { height: 70px; }
        .school-title { font-size: 1.3em; font-weight: bold; text-align: right; }
        .school-slogan { font-size: 0.95em; color: #444; text-align: right; }
        .red-line { border-top: 2px solid #a00; margin: 8px 0 6px 0; }
        .info-row { width: 100%; font-size: 1em; font-weight: bold; margin-bottom: 6px; }
        .info-row td { padding: 2px 6px; }
        table.absence-list { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
        table.absence-list th, table.absence-list td { border: 1px solid #bbb; padding: 2px 2px; text-align: center; }
        table.absence-list th { background: #f8f8f8; font-weight: bold; }
        table.absence-list td.name { text-align: left; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        table.absence-list td.billing { font-size: 9px; }
        table.absence-list td.st { font-weight: bold; }
        .st-paid { color: #1a7f37; font-size: 13px; }
        .st-unpaid { color: #a00; }
        .st-rest { color: #e67e22; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 30%;">
                <img src="{{ public_path('logo.png') }}" class="logo" alt="Logo">
            </td>
            <td style="width: 70%; text-align: right;">
                <div class="school-title">Tiko School</div>
                <div class="school-slogan">votre guide du succès</div>
            </td>
        </tr>
    </table>
    <div class="red-line"></div>
    <table class="info-row">
        <tr>
            <td style="text-align:left;">Absence List</td>
            <td style="text-align:center;">Teacher: {{ $teacher->first_name }} {{ $teacher->last_name }}</td>
            <td style="text-align:center;">Level: {{ optional($class->level)->name }}</td>
            <td style="text-align:right;">Date: {{ $date ? substr($date,0,7) : date('Y-m') }}</td>
        </tr>
    </table>
    <table class="absence-list">
        <thead>
            <tr>
                <th style="width: 80px;">Name</th>
                <th style="width: 80px;">Billing Date</th>
                <th style="width: 18px;">ST</th>
                @php
                    // Determine number of days in the selected month
                    $month = 1; $year = date('Y');
                    if (!empty($date)) {
                        $parts = explode('-', substr($date, 0, 10));
                        if (count($parts) >= 2) {
                            $year = (int)$parts[0];
                            $month = (int)$parts[1];
                        }
                    }
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                @endphp
                @for ($d = 1; $d <= $daysInMonth; $d++)
                    <th style="width: 14px;">{{ str_pad($d, 2, '0', STR_PAD_LEFT) }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach($students as $student)
            <tr>
                <td class="name" style="width: 80px;">{{ strtoupper($student->lastName . ' ' . $student->firstName) }}</td>
                <td class="billing" style="width: 80px;">{{ $student->billingDate ?? '' }}</td>
                <td class="st">
                    @php
                        $icons = [];
                        if (isset($student->memberships) && count($student->memberships)) {
                            foreach ($student->memberships as $membership) {
                                if ($membership->payment_status === 'paid') {
                                    $icons[] = '<span class="st-paid">&#10003;</span>'; // green check
                                } elseif ($membership->payment_status === 'rest') {
                                    $icons[] = '<span class="st-rest">&#x25B2;</span>'; // orange triangle for rest
                                } else {
                                    $icons[] = '<span class="st-unpaid">&#10007;</span>'; // red X
                                }
                            }
                        }
                        echo implode('', $icons) ?: '<span style="color:#bbb;">-</span>';
                    @endphp
                </td>
                @for ($d = 1; $d <= $daysInMonth; $d++)
                    <td></td>
                @endfor
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html> 