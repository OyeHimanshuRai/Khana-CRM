<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt · {{ $shop?->name ?: $company }}</title>
    <style>
        body { margin: 0; padding: 16px; background: #f5f5f7; font: 15px/1.45 -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d1d1f; }
        .t-receipt { max-width: 460px; margin: 0 auto; background: #fff; border: 1px solid #e5e5ea; border-radius: 16px; padding: 18px; }
        {!! file_get_contents(public_path('assets/css/table-receipt.css')) !!}
    </style>
</head>
<body>
    <div class="t-receipt">
        @include('table._receipt')
    </div>
</body>
</html>
