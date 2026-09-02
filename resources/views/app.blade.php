<!DOCTYPE html>
@php($theme = in_array(request()->cookie('outreach_theme'), ['light', 'dark', 'black'], true) ? request()->cookie('outreach_theme') : 'light')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Outreach') }}</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%237c3aed'/%3E%3Cstop offset='1' stop-color='%23d946ef'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cpath d='M51 21 15.5 35.5c-1.8.75-1.7 3.3.15 3.9l10.1 3.3 3.3 10.1c.6 1.85 3.15 1.95 3.9.15L47.5 17.5' fill='none' stroke='%23fff' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M26 42.5 48.5 24' fill='none' stroke='%23fff' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">

        {{-- Fonts load asynchronously so a slow font host can never block first paint. --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap"
              onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet"></noscript>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-app text-body">
        @inertia
    </body>
</html>
