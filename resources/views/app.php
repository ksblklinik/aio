<!DOCTYPE html>
<?php /** @var array{component: string, props: array<string, mixed>, url: string, version: string} $page */ ?>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['props']['title'] ?? 'aio - Full-Stack SaaS Framework') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
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
      })();
    </script>

    <?= vite_asset('resources/js/app.tsx') ?>
</head>
<body class="h-full antialiased font-sans bg-canvas text-text-main selection:bg-brand-primary selection:text-white transition-colors duration-150">
    <div id="app" data-page="<?= htmlspecialchars((string) json_encode($page), ENT_QUOTES, 'UTF-8') ?>"></div>
</body>
</html>
