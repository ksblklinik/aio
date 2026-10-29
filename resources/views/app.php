<!DOCTYPE html>
<?php 
/** @var array{component: string, props: array<string, mixed>, url: string, version: string} $page */ 
$appName = $page['props']['app']['name'] ?? 'aio SaaS';
$appTagline = $page['props']['app']['tagline'] ?? 'Unified Platform';
$appFavicon = $page['props']['app']['favicon'] ?? '/favicon.ico';
$pageTitle = $page['props']['title'] ?? null;
$renderedTitle = $pageTitle ? "{$pageTitle} - {$appName}" : "{$appName} - {$appTagline}";
?>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($renderedTitle) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($appFavicon) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- PWA Metadata & Mobile Shell -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Anti-FOUC Theme Initializer -->
    <script>
      (function() {
        try {
          var saved = localStorage.getItem('theme');
          var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
          if (saved === 'dark' || (!saved && prefersDark)) {
            document.documentElement.classList.add('dark');
          } else {
            document.documentElement.classList.remove('dark');
          }
        } catch (e) {}

        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
          window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js').catch(function() {});
          });
        }
      })();
    </script>

    <?= vite_asset('resources/js/app.tsx') ?>
</head>
<body class="h-full antialiased font-sans bg-canvas text-text-main selection:bg-brand-primary selection:text-white transition-colors duration-150">
    <div id="app" data-page="<?= htmlspecialchars((string) json_encode($page), ENT_QUOTES, 'UTF-8') ?>"></div>
</body>
</html>
