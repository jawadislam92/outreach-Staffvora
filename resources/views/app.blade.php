<!DOCTYPE html>
@php($theme = in_array(request()->cookie('outreach_theme'), ['light', 'dark', 'black'], true) ? request()->cookie('outreach_theme') : 'light')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Outreach') }}</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%232563eb'/%3E%3Cpath d='M14 22h36v22H14z' fill='none' stroke='%23fff' stroke-width='4' stroke-linejoin='round'/%3E%3Cpath d='M14 24l18 12 18-12' fill='none' stroke='%23fff' stroke-width='4' stroke-linejoin='round'/%3E%3C/svg%3E">

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
