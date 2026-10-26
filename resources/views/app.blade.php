<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico?v=2" sizes="any">
        <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">

        <meta property="og:locale" content="en_GB">
        <meta name="twitter:site" content="@freekmurze">
        <meta name="theme-color" content="#292a22">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/social.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            @if(isset($page['props']['socialPreview']))
                @php($preview = $page['props']['socialPreview'])
                <title>{{ $preview['title'] }}</title>
                <meta data-inertia="description" name="description" content="{{ $preview['description'] }}">
                @foreach([
                    'og:site_name' => 'Plaat Club', 'og:type' => $preview['type'],
                    'og:title' => $preview['title'], 'og:description' => $preview['description'],
                    'og:url' => $preview['url'], 'og:image' => $preview['image'],
                    'og:image:alt' => $preview['imageAlt'], 'og:image:width' => '1200',
                    'og:image:height' => '630', 'og:image:type' => 'image/jpeg',
                ] as $property => $content)
                    <meta data-inertia="{{ $property }}" property="{{ $property }}" content="{{ $content }}">
                @endforeach
                @foreach([
                    'twitter:card' => 'summary_large_image', 'twitter:title' => $preview['title'],
                    'twitter:description' => $preview['description'], 'twitter:image' => $preview['image'],
                    'twitter:image:alt' => $preview['imageAlt'],
                ] as $name => $content)
                    <meta data-inertia="{{ $name }}" name="{{ $name }}" content="{{ $content }}">
                @endforeach
                <link data-inertia="canonical" rel="canonical" href="{{ $preview['url'] }}">
            @else
                <title>{{ config('app.name', 'Laravel') }}</title>
            @endif
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
