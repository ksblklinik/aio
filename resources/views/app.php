<!DOCTYPE html>
<?php /** @var array{component: string, props: array<string, mixed>, url: string, version: string} $page */ ?>
<html lang="id" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['props']['title'] ?? 'aio - Full-Stack SaaS Framework') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <?= vite_asset('resources/js/app.tsx') ?>
</head>
<body class="h-full antialiased font-sans bg-slate-950 text-slate-100 selection:bg-emerald-500 selection:text-white">
    <div id="app" data-page="<?= htmlspecialchars((string) json_encode($page), ENT_QUOTES, 'UTF-8') ?>"></div>
</body>
</html>
