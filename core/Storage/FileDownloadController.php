<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;

class FileDownloadController
{
    public function __construct(
        private StorageManager $storage,
        private Connection $db
    ) {
    }

    public function download(Request $request, string $id): Response
    {
        $fileId = (int) $id;
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        // 1. Ambil record file dari database (akses bypass tenancy jika signature sah)
        $file = $this->db->selectOne("SELECT * FROM files WHERE id = ?", [$fileId]);
        if (!$file) {
            return new Response("Berkas tidak ditemukan.", 404);
        }

        // 2. Jika bukan berkas publik, validasi tanda tangan kriptografis dan TTL
        if ((int) $file['is_public'] !== 1) {
            if (!$this->storage->validateSignature($fileId, $expires, $signature)) {
                return new Response("Tautan unduhan tidak sah atau telah kedaluwarsa.", 403);
            }
        }

        // 3. Ambil isi berkas fisik dari storage
        $tenantPath = "tenants/{$file['tenant_id']}/{$file['file_path']}";
        $content = $this->storage->getDriver()->get($tenantPath);

        if ($content === null) {
            return new Response("Berkas fisik tidak ditemukan di media penyimpanan.", 404);
        }

        $headers = [
            'Content-Type' => $file['mime_type'] ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($file['original_name'])),
            'Cache-Control' => 'private, max-age=3600',
        ];

        return new Response($content, 200, $headers);
    }
}
