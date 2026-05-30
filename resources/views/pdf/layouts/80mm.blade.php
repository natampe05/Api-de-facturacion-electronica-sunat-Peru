@extends('pdf.layouts.base')

@section('format-styles')
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-weight: bold !important;
        }

        @page {
            margin: 0;
        }

        body {
            font-family: 'Helvetica', Arial, sans-serif;
            font-weight: bold;
            margin: 0;
            padding: 10pt;
            width: 80mm;
            background-color: white;
            font-size: 8.5px;
        }

        .container {
            width: 100%;
            margin: 0;
            padding: 0;
        }

        .ticket-container {
            width: 100%;
            background-color: white;
            margin: 0;
            padding: 0;
        }

        .ticket {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        /* ================= HEADER ================= */
        .header {
            text-align: center;
            margin-bottom: 3px;
            padding-bottom: 3px;
            border-bottom: 1px dashed #ccc;
        }

        .logo-section-ticket {
            text-align: center;
            margin-bottom: 2px;
        }

        .logo-img-ticket {
            width: 120px;
            height: 50;
            object-fit: contain;
            display: block;
            margin: 0 auto 2px;
            padding: 2px;
        }

        .company-name {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 2px;
            text-transform: uppercase;
            color: #000;
        }

        .company-ruc {
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 1px;
        }

        .company-details {
            font-size: 8.5px;
            line-height: 1.2;
            margin-bottom: 3px;
        }

        /* ================= DOCUMENT TITLE ================= */
        .document-title {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
            text-transform: uppercase;
            padding: 3px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }

        .document-number {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 5px;
        }

        /* ================= CLIENT INFO ================= */
        .client-section {
            margin: 4px 0;
            font-size: 9px;
            padding: 3px 0;
            border-bottom: 1px dashed #ccc;
        }

        .client-name {
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            margin-bottom: 2px;
        }

        .client-separator {
            text-align: center;
            margin: 2px 0;
            font-size: 9px;
        }

        .client-details {
            font-size: 9px;
            margin-bottom: 3px;
            text-align: center;
        }

        /* ================= ITEMS TABLE ================= */
        .items-header {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 2px 0;
            font-size: 9px;
            font-weight: bold;
            margin: 3px 0;
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .items-header>div {
            display: table-cell;
            text-align: left;
            vertical-align: middle;
            padding: 1px;
        }

        .header-cant {
            width: 12%;
        }

        .header-um {
            width: 13%;
        }

        .header-cod {
            width: 25%;
        }

        .header-precio {
            width: 25%;
            text-align: right;
            padding-right: 3px;
        }

        .header-total {
            width: 25%;
            text-align: right;
            padding-right: 3px;
        }

        .header-desc {
            width: 15%;
        }

        .items-section {
            margin: 3px 0;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }

        .item {
            margin-bottom: 2px;
            font-size: 9px;
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .item>div {
            display: table-cell;
            text-align: left;
            vertical-align: top;
            padding: 1px;
        }

        .item-cant {
            width: 12%;
        }

        .item-um {
            width: 13%;
        }

        .item-cod {
            width: 25%;
        }

        .item-precio {
            width: 25%;
            text-align: right;
            padding-right: 3px;
        }

        .item-total {
            width: 25%;
            text-align: right;
            padding-right: 3px;
        }

        .item-desc {
            width: 15%;
        }

        .item-descripcion {
            font-size: 9px;
            text-align: left;
            margin-top: 1px;
        }

        /* ================= TOTALS ================= */
        .totals-section {
            margin: 3px 0;
            font-size: 9px;
            border-top: 1px solid #000;
            padding-top: 2px;
        }

        .total-line {
            display: block;
            width: 100%;
            margin-bottom: 1px;
            font-weight: bold;
            font-size: 9px;
            line-height: 1.3;
            position: relative;
        }

        .total-text {
            display: inline-block;
            float: left;
            font-weight: bold;
        }

        .total-value {
            display: inline-block;
            float: right;
            font-weight: bold;
        }

        .total-dots {
            display: inline-block;
            float: left;
            font-weight: normal;
            letter-spacing: 0.5px;
            overflow: hidden;
            margin: 0 2px;
        }

        .total-final {
            border-top: 1px solid #000;
            padding-top: 2px;
            margin-top: 2px;
            font-size: 9px;
        }

        .total-final .total-text,
        .total-final .total-value {
            font-size: 9px;
            font-weight: bold;
        }

        /* Clear floats */
        .total-line::after {
            content: "";
            display: table;
            clear: both;
        }

        .total-letras {
            font-size: 9px;
            font-weight: bold;
            margin: 3px 0;
            text-align: left;
        }

        /* ================= PAYMENT INFO ================= */
        .payment-info {
            font-size: 9px;
            margin: 3px 0;
            text-align: left;
            padding: 3px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }

        .payment-info div {
            margin-bottom: 1px;
        }

        /* ================= QR AND FOOTER ================= */
        .qr-section {
            text-align: center;
            margin: 5px 0;
            padding: 5px 0;
            border-bottom: 1px dashed #ccc;
        }

        .qr-code img {
            width: 100px;
            height: 100px;
            margin: 3px 0;
        }

        .footer-text {
            font-size: 8px;
            text-align: center;
            line-height: 1.2;
            margin: 2px 0;
        }

        .footer-url {
            font-size: 8px;
            text-align: center;
            font-weight: bold;
            margin: 2px 0;
        }

        .footer-auth {
            font-size: 8px;
            text-align: center;
            margin: 2px 0;
        }

        .powered-by {
            font-size: 8px;
            text-align: center;
            margin-top: 2px;
            color: #888;
        }

        /* ================= UTILITIES ================= */
        .text-bold {
            font-weight: bold;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        /* ================= PRINT STYLES ================= */
        @media print {
            body {
                background: none;
                margin: 0;
                padding: 0;
            }

            .ticket-container {
                box-shadow: none;
                border-radius: 0;
                width: 80mm;
            }

            .ticket {
                width: 80mm;
                padding: 0;
                margin: 0;
            }

            .no-print {
                display: none;
            }
        }

        /* ================= ACTION BUTTONS ================= */
        .actions {
            text-align: center;
            margin-top: 20px;
        }

        .btn {
            background-color: #4CAF50;
            border: none;
            color: white;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #45a049;
        }
    </style>
@endsection

@section('body-content')
    <div class="container">
        @yield('content')
    </div>
@endsection
