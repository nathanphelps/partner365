<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Inter, system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(to bottom, #eef2ff, #ffffff);
            color: #1f2937;
        }
        .container { text-align: center; padding: 2rem; }
        .shield { width: 64px; height: 64px; margin: 0 auto 1rem; color: #4f46e5; }
        .app-name { font-size: 1.125rem; font-weight: 600; color: #111827; margin-bottom: 2rem; }
        .code { font-size: 4rem; font-weight: 700; color: #4f46e5; line-height: 1; }
        .message { font-size: 1.125rem; color: #6b7280; margin-top: 0.5rem; max-width: 28rem; margin-left: auto; margin-right: auto; }
        .home-link {
            display: inline-flex; align-items: center; gap: 0.5rem;
            margin-top: 2rem; padding: 0.625rem 1.5rem;
            background: #4f46e5; color: #fff; font-size: 0.875rem; font-weight: 500;
            border-radius: 0.5rem; text-decoration: none; transition: background 0.15s;
        }
        .home-link:hover { background: #4338ca; }
        @media (prefers-color-scheme: dark) {
            body { background: linear-gradient(to bottom, #1e1b4b, #0a0a0a); color: #e5e7eb; }
            .app-name { color: #f3f4f6; }
            .code { color: #818cf8; }
            .message { color: #9ca3af; }
            .shield { color: #818cf8; }
        }
    </style>
</head>
<body>
    <div class="container">
        <svg class="shield" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
            <path fill="currentColor" d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm0 4.4L38 12v10c0 9.4-6.5 18.2-14 20.7V4.4zM10 12l14-5.6v38.3C16.5 42.2 10 33.4 10 22V12z" opacity="0.15"/>
            <path fill="currentColor" d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm14 20c0 9.4-6.5 18.2-14 20.7C16.5 40.2 10 31.4 10 22V12l14-5.6L38 12v10z"/>
            <text x="24" y="28" text-anchor="middle" fill="currentColor" font-family="Inter, system-ui, sans-serif" font-size="11" font-weight="700">365</text>
        </svg>
        <div class="app-name">Partner365</div>
        <div class="code">@yield('code')</div>
        <p class="message">@yield('message')</p>
        <a href="/" class="home-link">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
            </svg>
            Go Home
        </a>
    </div>
</body>
</html>
