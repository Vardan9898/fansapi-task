<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FansAPI profiles')</title>
    {{-- Deliberately no build step: plain CSS so the project runs with PHP only --}}
    <style>
        :root { --border: #e3e3e0; --muted: #706f6c; --bg: #fafaf9; --green: #15803d; --amber: #b45309; --red: #b91c1c; --blue: #1d4ed8; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1b1b18; background: var(--bg); }
        header { display: flex; gap: 16px; align-items: center; padding: 12px 24px; background: #fff; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
        header h1 { font-size: 16px; margin: 0 auto 0 0; }
        main { padding: 20px 24px; max-width: 1400px; }
        h2 { font-size: 15px; margin: 24px 0 8px; }
        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--border); }
        th, td { padding: 7px 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
        th { font-weight: 600; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .03em; background: #f5f5f4; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); font-size: 12px; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .badge.green { background: #dcfce7; color: var(--green); }
        .badge.amber { background: #fef3c7; color: var(--amber); }
        .badge.red { background: #fee2e2; color: var(--red); }
        .badge.grey { background: #e7e5e4; color: var(--muted); }
        tr.row-failing td { background: #fff7f7; }
        tr.row-stale td, tr.row-never td { background: #fffbeb; }
        form.inline { display: inline-flex; gap: 4px; align-items: center; margin: 0; }
        input, select, button { font: inherit; font-size: 13px; padding: 3px 8px; border: 1px solid #d6d3d1; border-radius: 4px; background: #fff; }
        button { cursor: pointer; background: #f5f5f4; }
        button:hover { background: #e7e5e4; }
        button.danger { color: var(--red); }
        .flash { padding: 8px 12px; margin-bottom: 12px; border-radius: 4px; }
        .flash.ok { background: #dcfce7; color: var(--green); }
        .flash.error { background: #fee2e2; color: var(--red); }
        nav.pages { margin-top: 10px; display: flex; gap: 14px; }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
