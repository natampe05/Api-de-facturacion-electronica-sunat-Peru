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
            padding: 5pt;
            width: 50mm;
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
            margin-bottom: 2px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #ccc;
        }

        .logo-section-ticket {
            text-align: center;
            margin-bottom: 1px;
        }

        .logo-img-ticket {
            width: 75px;
            height: 31px;
            object-fit: contain;
            display: block;
            margin: 0 auto 1px;
            padding: 1px;
        }

        .company-name {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 1px;
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
            line-height: 1.1;
            margin-bottom: 2px;
        }

        /* ================= DOCUMENT TITLE ================= */
        .document-title {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin: 3px 0;
            text-transform: uppercase;
            padding: 2px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }

        .document-number {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 3px;
        }

        /* ================= CLIENT INFO ================= */
        .client-section {
            margin: 3px 0;
            font-size: 8.5px;
            padding: 2px 0;
            border-bottom: 1px dashed #ccc;
        }

        .client-name {
            font-weight: bold;
            font-size: 8.5px;
            text-align: center;
            margin-bottom: 1px;
        }

        .client-separator {
            text-align: center;
            margin: 1px 0;
            font-size: 8.5px;
        }

        .client-details {
            font-size: 8.5px;
            margin-bottom: 2px;
            text-align: center;
        }

        /* ================= ITEMS TABLE ================= */
        .items-header {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 1px 0;
            font-size: 6.5px;
            font-weight: bold;
            margin: 2px 0;
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
            padding-right: 2px;
        }

        .header-total {
            width: 25%;
            text-align: right;
            padding-right: 2px;
        }

        .header-desc {
            width: 15%;
        }

        .items-section {
            margin: 2px 0;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
        }

        .item {
            margin-bottom: 1px;
            font-size: 6.5px;
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
            padding-right: 2px;
        }

        .item-total {
            width: 25%;
            text-align: right;
            padding-right: 2px;
        }

        .item-desc {
            width: 15%;
        }

        .item-descripcion {
            font-size: 6.5px;
            text-align: left;
            margin-top: 1px;
        }

        /* ================= TOTALS ================= */
        .totals-section {
            margin: 2px 0;
            font-size: 6.5px;
            border-top: 1px solid #000;
            padding-top: 1px;
        }

        .total-line {
            display: block;
            width: 100%;
            margin-bottom: 1px;
            font-weight: bold;
            font-size: 6.5px;
            line-height: 1.2;
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
            letter-spacing: 0.3px;
            overflow: hidden;
            margin: 0 1px;
        }

        .total-final {
            border-top: 1px solid #000;
            padding-top: 1px;
            margin-top: 1px;
            font-size: 7.5px;
        }

        .total-final .total-text,
        .total-final .total-value {
            font-size: 7.5px;
            font-weight: bold;
        }

        /* Clear floats */
        .total-line::after {
            content: "";
            display: table;
            clear: both;
        }

        .total-letras {
            font-size: 7.5px;
            font-weight: bold;
            margin: 2px 0;
            text-align: left;
        }

        /* ================= PAYMENT INFO ================= */
        .payment-info {
            font-size: 6.5px;
            margin: 2px 0;
            text-align: left;
            padding: 2px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }

        .payment-info div {
            margin-bottom: 1px;
        }

        /* ================= QR AND FOOTER ================= */
        .qr-section {
            text-align: center;
            margin: 3px 0;
            padding: 3px 0;
            border-bottom: 1px dashed #ccc;
        }

        .qr-code img {
            width: 60px;
            height: 60px;
            margin: 2px 0;
        }

        .footer-text {
            font-size: 7px;
            text-align: center;
            line-height: 1.1;
            margin: 1px 0;
        }

        .footer-url {
            font-size: 7px;
            text-align: center;
            font-weight: bold;
            margin: 1px 0;
        }

        .footer-auth {
            font-size: 6.5px;
            text-align: center;
            margin: 1px 0;
        }

        .powered-by {
            font-size: 6.5px;
            text-align: center;
            margin-top: 1px;
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
                width: 50mm;
            }

            .ticket {
                width: 50mm;
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
            margin-top: 10px;
        }

        .btn {
            background-color: #4CAF50;
            border: none;
            color: white;
            padding: 5px 10px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
            margin: 2px 1px;
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