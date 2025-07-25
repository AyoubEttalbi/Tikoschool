<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 12px; /* Reduce base font size */
            background-color: #f9fafb; /* Light background */
        }
        .logo {
            text-align: center;
            margin-bottom: 16px;
        }
        .logo img {
            width: 80px; /* Slightly smaller logo */
            height: auto;
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 16px;
        }
        h1 {
            font-size: 16px;
            margin: 0;
            color: #3730a3; /* Purple heading */
        }
        p {
            margin: 4px 0;
            font-size: 11px;
            color: #4b5563; /* Subtle gray text */
        }
        .invoice-details {
            margin-top: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            border-radius: 8px;
            overflow: hidden; /* Rounded corners */
            background-color: #ffffff; /* White background */
        }
        table, th, td {
            border: 1px solid #e5e7eb; /* Light border */
        }
        th, td {
            padding: 8px;
            text-align: left;
            font-size: 11px;
        }
        th {
            background-color: #f3f4f6; /* Light gray header */
            color: #3730a3; /* Purple text */
        }
        td {
            color: #4b5563; /* Subtle gray text */
        }
        .separator {
            border-top: 1px dashed #d1d5db; /* Light dashed separator */
            margin: 16px 0;
        }
        .footer {
            text-align: center;
            margin-top: 6px;
            font-size: 10px;
            color: #4b5563; /* Subtle gray text */
        }
        
    </style>
</head>
<body>
    <!-- School Copy -->
    <div class="copy">
        <div class="logo">
            <img src="{{ public_path('logo.png') }}" alt="School Logo">
        </div>
        <div class="invoice-header">
            <p>Invoice ID: {{ $invoice->id }}</p>
            <p>Date: {{ $invoice->creationDate->format('Y-m-d') }}</p>
        </div>
        <div class="invoice-details">
            <table>
                <tr>
                    <th>Student Name</th>
                    <td>{{ $student->firstName }} {{ $student->lastName }}</td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td>{{ $student->address }}</td>
                </tr>
                <tr>
                    <th>Phone Number</th>
                    <td>{{ $student->phoneNumber }}</td>
                </tr>
                <tr>
                    <th>Email</th>
                    <td>{{ $student->email }}</td>
                </tr>
                <tr>
                    <th>Offer Name</th>
                    <td>{{ $offerName }}</td>
                </tr>
                <tr>
                    <th>Bill Date</th>
                    <td>{{ $invoice->billDate->format('Y-m') }}</td>
                </tr>
                <tr>
                    <th>Months</th>
                    <td>{{ $invoice->months }}</td>
                </tr>
                <tr>
                    <th>Total Amount</th>
                    <td><span class="currency">{{ number_format($invoice->totalAmount, 2) }} DH</span></td>
                </tr>
                <tr>
                    <th>Amount Paid</th>
                    <td><span class="currency">{{ number_format($invoice->amountPaid, 2) }} DH</span></td>
                </tr>
                <tr>
                    <th>Rest</th>
                    <td><span class="currency">{{ number_format($invoice->rest, 2) }} DH</span></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Separator Line -->
    <div class="separator"></div>

    <!-- Student Copy -->
    <div class="copy">
        <div class="logo">
            <img src="{{ public_path('logo.png') }}" alt="School Logo">
        </div>
        <div class="invoice-header">
            <p>Invoice ID: {{ $invoice->id }}</p>
            <p>Date: {{ $invoice->creationDate->format('Y-m-d') }}</p>
        </div>
        <div class="invoice-details">
            <table>
                <tr>
                    <th>Student Name</th>
                    <td>{{ $student->firstName }} {{ $student->lastName }}</td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td>{{ $student->address }}</td>
                </tr>
                <tr>
                    <th>Phone Number</th>
                    <td>{{ $student->phoneNumber }}</td>
                </tr>
                <tr>
                    <th>Email</th>
                    <td>{{ $student->email }}</td>
                </tr>
                <tr>
                    <th>Offer Name</th>
                    <td>{{ $offerName }}</td>
                </tr>
                <tr>
                    <th>Bill Date</th>
                    <td>{{ $invoice->billDate->format('Y-m') }}</td>
                </tr>
                <tr>
                    <th>Months</th>
                    <td>{{ $invoice->months }}</td>
                </tr>
                <tr>
                    <th>Total Amount</th>
                    <td><span class="currency">{{ number_format($invoice->totalAmount, 2) }} DH</span></td>
                </tr>
                <tr>
                    <th>Amount Paid</th>
                    <td><span class="currency">{{ number_format($invoice->amountPaid, 2) }} DH</span></td>
                </tr>
                <tr>
                    <th>Rest</th>
                    <td><span class="currency">{{ number_format($invoice->rest, 2) }} DH</span></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="footer">
        Thank you for your business!
    </div>
</body>
</html>