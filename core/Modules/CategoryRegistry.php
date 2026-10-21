<?php

declare(strict_types=1);

namespace Core\Modules;

class CategoryRegistry
{
    /**
     * @var array<string, array{id: string, label: string, icon: string, color: string, badge_class: string, description: string}>
     */
    private const CATEGORIES = [
        'core' => [
            'id' => 'core',
            'label' => 'Core & Keamanan',
            'icon' => 'ShieldCheck',
            'color' => 'purple',
            'badge_class' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
            'description' => 'Fondasi Keamanan, Akun, Multi-Tenancy & Pengaturan Sistem',
        ],
        'collaboration' => [
            'id' => 'collaboration',
            'label' => 'Kolaborasi & Alur Kerja',
            'icon' => 'Layers',
            'color' => 'blue',
            'badge_class' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
            'description' => 'Alur Kerja, Agenda, Inisiatif/Proyek, Penugasan, Disposisi Tugas',
        ],
        'people' => [
            'id' => 'people',
            'label' => 'Anggota & Keanggotaan',
            'icon' => 'Users',
            'color' => 'emerald',
            'badge_class' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'description' => 'Direktori Anggota/Warga/Relawan/Siswa, Profil, Presensi Kehadiran',
        ],
        'knowledge' => [
            'id' => 'knowledge',
            'label' => 'Pengetahuan & Dokumen',
            'icon' => 'BookOpen',
            'color' => 'indigo',
            'badge_class' => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
            'description' => 'Arsip Dokumen, Wiki/SOP, Publikasi, Perpustakaan & Inventaris',
        ],
        'resources' => [
            'id' => 'resources',
            'label' => 'Sumber Daya & Finansial',
            'icon' => 'Coins',
            'color' => 'amber',
            'badge_class' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            'description' => 'Kas Organisasi, Iuran Anggota, Donasi Publik, Invoice & Billing',
        ],
        'engagement' => [
            'id' => 'engagement',
            'label' => 'Komunikasi & Pengumuman',
            'icon' => 'MessageSquare',
            'color' => 'sky',
            'badge_class' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20',
            'description' => 'Forum Diskusi, Siaran Pengumuman, Notifikasi Pesan & Berita',
        ],
        'analytics' => [
            'id' => 'analytics',
            'label' => 'Analitik & Laporan',
            'icon' => 'BarChart3',
            'color' => 'rose',
            'badge_class' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            'description' => 'Laporan Pertanggungjawaban (LPJ), Metrik & Statistik Kinerja',
        ],
        'developer' => [
            'id' => 'developer',
            'label' => 'Integrasi & Ekstensi',
            'icon' => 'Boxes',
            'color' => 'slate',
            'badge_class' => 'bg-slate-500/10 text-slate-600 dark:text-slate-400 border-slate-500/20',
            'description' => 'Webhooks, REST API Keys, Integrasi Sistem Eksternal & Plugin',
        ],
    ];

    /**
     * Map alias legacy category names to new standard ID
     * @var array<string, string>
     */
    private const ALIASES = [
        'general' => 'collaboration',
        'bisnis' => 'collaboration',
        'produktivitas' => 'collaboration',
        'keamanan & akses' => 'core',
        'infrastruktur tenancy' => 'core',
        'sistem & administrasi' => 'core',
        'keuangan' => 'resources',
        'finance' => 'resources',
        'billing' => 'resources',
        'anggota' => 'people',
        'keanggotaan' => 'people',
        'dokumen' => 'knowledge',
        'pengetahuan' => 'knowledge',
        'crm' => 'people',
        'komunikasi' => 'engagement',
        'komunikasi & crm' => 'engagement',
        'pengumuman' => 'engagement',
        'laporan' => 'analytics',
        'analitik' => 'analytics',
    ];

    /**
     * @return array<int, array{id: string, label: string, icon: string, color: string, badge_class: string, description: string}>
     */
    public static function all(): array
    {
        return array_values(self::CATEGORIES);
    }

    /**
     * @return array<string, array{id: string, label: string, icon: string, color: string, badge_class: string, description: string}>
     */
    public static function map(): array
    {
        return self::CATEGORIES;
    }

    public static function get(string $key): ?array
    {
        $k = strtolower(trim($key));
        if (isset(self::CATEGORIES[$k])) {
            return self::CATEGORIES[$k];
        }
        if (isset(self::ALIASES[$k])) {
            return self::CATEGORIES[self::ALIASES[$k]] ?? null;
        }
        return null;
    }

    public static function isValid(string $key): bool
    {
        $k = strtolower(trim($key));
        return isset(self::CATEGORIES[$k]) || isset(self::ALIASES[$k]);
    }

    public static function normalizeKey(string $key): string
    {
        $k = strtolower(trim($key));
        if (isset(self::CATEGORIES[$k])) {
            return $k;
        }
        if (isset(self::ALIASES[$k])) {
            return self::ALIASES[$k];
        }
        return 'collaboration';
    }

    public static function getLabel(string $key): string
    {
        $cat = self::get($key);
        return $cat ? $cat['label'] : ucwords(str_replace(['_', '-'], ' ', $key));
    }

    public static function getBadgeClass(string $key): string
    {
        $cat = self::get($key);
        return $cat ? $cat['badge_class'] : 'bg-slate-500/10 text-slate-700 dark:text-slate-300 border-slate-500/20';
    }

    public static function getIcon(string $key): string
    {
        $cat = self::get($key);
        return $cat ? $cat['icon'] : 'Package';
    }
}
