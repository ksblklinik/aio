<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Broadcasting\SseBroadcasterInterface;
use Core\Console\Command;

class SsePruneCommand extends Command
{
    protected string $signature = 'sse:prune';
    protected string $description = 'Bersihkan rekaman event Server-Sent Events (SSE) yang melampaui batas waktu retensi';

    public function handle(array $arguments = [], array $options = []): int
    {
        $minutes = isset($options['minutes']) && is_numeric($options['minutes'])
            ? (int)$options['minutes']
            : 60;

        /** @var SseBroadcasterInterface $broadcaster */
        $broadcaster = $this->container->get(SseBroadcasterInterface::class);

        $this->line("Memangkas event SSE yang lebih lama dari {$minutes} menit...");
        $prunedCount = $broadcaster->prune($minutes);

        $this->info("✓ Berhasil memangkas {$prunedCount} event usang dari tabel sse_events.");

        return 0;
    }
}
