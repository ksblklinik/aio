<?php

declare(strict_types=1);

namespace Core\Graph;

use InvalidArgumentException;

class GraphHelper
{
    /**
     * Dapatkan seluruh turunan (descendants) dari suatu node menggunakan Breadth-First / Depth-First search
     *
     * @param array<int, array<string, mixed>> $items Daftar elemen datar (flat adjacency list)
     * @param string|int $rootId ID simpul induk
     * @param string $idKey Kunci pengenal unik (default: 'id')
     * @param string $parentKey Kunci induk pengenal (default: 'parent_id')
     * @param int $maxDepth Batas kedalaman maksimal penelusuran (mencegah loop tak terhingga)
     * @return array<int, array<string, mixed>> Daftar seluruh turunan
     */
    public static function getDescendants(
        array $items,
        string|int $rootId,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
        int $maxDepth = 50
    ): array {
        $childrenMap = [];
        $itemMap = [];

        foreach ($items as $item) {
            $id = (string) ($item[$idKey] ?? '');
            $parent = isset($item[$parentKey]) && $item[$parentKey] !== null ? (string) $item[$parentKey] : '';

            $itemMap[$id] = $item;
            $childrenMap[$parent][] = $id;
        }

        $descendants = [];
        $queue = [[(string) $rootId, 1]];
        $visited = [(string) $rootId => true];

        while (!empty($queue)) {
            [$currentId, $depth] = array_shift($queue);

            if ($depth > $maxDepth) {
                break;
            }

            if (isset($childrenMap[$currentId])) {
                foreach ($childrenMap[$currentId] as $childId) {
                    if (isset($visited[$childId])) {
                        // Lewati simpul yang sudah dikunjungi untuk mencegah siklus
                        continue;
                    }

                    $visited[$childId] = true;
                    if (isset($itemMap[$childId])) {
                        $childItem = $itemMap[$childId];
                        $childItem['_depth'] = $depth;
                        $descendants[] = $childItem;
                    }

                    $queue[] = [$childId, $depth + 1];
                }
            }
        }

        return $descendants;
    }

    /**
     * Dapatkan rantai leluhur (ancestors / breadcrumb path) dari bawah ke atas hingga root
     *
     * @param array<int, array<string, mixed>> $items
     * @param string|int $targetId
     * @return array<int, array<string, mixed>>
     */
    public static function getAncestors(
        array $items,
        string|int $targetId,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
        int $maxDepth = 50
    ): array {
        $itemMap = [];
        foreach ($items as $item) {
            $id = (string) ($item[$idKey] ?? '');
            $itemMap[$id] = $item;
        }

        $ancestors = [];
        $currentId = (string) $targetId;
        $visited = [];
        $depth = 0;

        while ($depth < $maxDepth && isset($itemMap[$currentId])) {
            $visited[$currentId] = true;
            $item = $itemMap[$currentId];
            $parentId = isset($item[$parentKey]) && $item[$parentKey] !== null ? (string) $item[$parentKey] : '';

            if ($parentId === '' || $parentId === '0' || !isset($itemMap[$parentId])) {
                break;
            }

            if (isset($visited[$parentId])) {
                // Siklus terdeteksi, hentikan loop
                break;
            }

            $parentItem = $itemMap[$parentId];
            $ancestors[] = $parentItem;
            $currentId = $parentId;
            $depth++;
        }

        return $ancestors;
    }

    /**
     * Ubah flat adjacency list menjadi nested tree hierarki bertingkat
     *
     * @param array<int, array<string, mixed>> $items
     * @param string|int|null $rootId Nilai parent_id simpul teratas (null atau '0')
     * @return array<int, array<string, mixed>>
     */
    public static function buildTree(
        array $items,
        string|int|null $rootId = null,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
        string $childrenKey = 'children'
    ): array {
        $targetRoot = $rootId !== null ? (string) $rootId : '';
        $childrenMap = [];
        $itemMap = [];

        foreach ($items as $item) {
            $id = (string) ($item[$idKey] ?? '');
            $parent = isset($item[$parentKey]) && $item[$parentKey] !== null ? (string) $item[$parentKey] : '';

            $item[$childrenKey] = [];
            $itemMap[$id] = $item;
            $childrenMap[$parent][] = $id;
        }

        $buildSubtree = function (string $parentId) use (&$buildSubtree, &$childrenMap, &$itemMap, $childrenKey): array {
            $branch = [];
            if (isset($childrenMap[$parentId])) {
                foreach ($childrenMap[$parentId] as $childId) {
                    if (isset($itemMap[$childId])) {
                        $node = $itemMap[$childId];
                        $node[$childrenKey] = $buildSubtree($childId);
                        $branch[] = $node;
                    }
                }
            }
            return $branch;
        };

        // Jika rootId tidak ditentukan, cari semua item yang parent-nya null, empty, atau tidak ada dalam daftar item
        if ($rootId === null) {
            $tree = [];
            foreach ($items as $item) {
                $parentId = isset($item[$parentKey]) && $item[$parentKey] !== null ? (string) $item[$parentKey] : '';
                if ($parentId === '' || $parentId === '0' || !isset($itemMap[$parentId])) {
                    $id = (string) ($item[$idKey] ?? '');
                    $node = $itemMap[$id];
                    $node[$childrenKey] = $buildSubtree($id);
                    $tree[] = $node;
                }
            }
            return $tree;
        }

        return $buildSubtree($targetRoot);
    }

    /**
     * Deteksi apakah terdapat referensi melingkar (circular dependency / cycle)
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string>|null Mengembalikan daftar ID yang membentuk siklus jika ada, atau null jika bebas siklus
     */
    public static function detectCycle(
        array $items,
        string $idKey = 'id',
        string $parentKey = 'parent_id'
    ): ?array {
        $parentMap = [];
        foreach ($items as $item) {
            $id = (string) ($item[$idKey] ?? '');
            $parent = isset($item[$parentKey]) && $item[$parentKey] !== null ? (string) $item[$parentKey] : null;
            if ($parent !== null && $parent !== '' && $parent !== '0') {
                $parentMap[$id] = $parent;
            }
        }

        foreach (array_keys($parentMap) as $startNode) {
            $visited = [];
            $curr = $startNode;

            while ($curr !== null && isset($parentMap[$curr])) {
                if (isset($visited[$curr])) {
                    $cyclePath = array_keys($visited);
                    $cyclePath[] = $curr;
                    return $cyclePath;
                }

                $visited[$curr] = true;
                $curr = $parentMap[$curr];
            }
        }

        return null;
    }
}
