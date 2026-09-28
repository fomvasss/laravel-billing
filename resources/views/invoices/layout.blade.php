{{-- Tables and plain CSS only: this has to render in dompdf as well as in a browser. DejaVu Sans carries Cyrillic. --}}
<!doctype html>
<html lang="{{ $document->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('billing::invoice.' . $document->type) }} {{ $document->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; margin: 0; }
        .page { padding: 32px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 4px 6px; text-align: left; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        .brand { font-size: 16px; font-weight: bold; }
        .right { text-align: right; }
        .parties td { width: 50%; padding-top: 16px; }
        .summary { font-size: 16px; font-weight: bold; margin-top: 18px; }
        .items { margin-top: 20px; }
        .items th { border-bottom: 1px solid #222; font-weight: bold; }
        .items td { border-bottom: 1px solid #ddd; }
        .totals td { font-size: 13px; font-weight: bold; padding-top: 10px; }
        .stamp { display: inline-block; border: 2px solid #2e7d32; color: #2e7d32; padding: 2px 8px; font-weight: bold; }
        .stamp.void { border-color: #c62828; color: #c62828; }
        .pay { margin-top: 16px; }
        .footer { margin-top: 32px; color: #666; }
    </style>
</head>
<body>
<div class="page">
    @yield('document')
</div>
</body>
</html>
