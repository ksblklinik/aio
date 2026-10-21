<?php

declare(strict_types=1);

namespace Core\Validation;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Session;
use PDO;

trait ValidatesRequests
{
    /**
     * Validasi data dari HTTP Request. Jika gagal, otomatis flash errors ke session lalu lempar ValidationException.
     *
     * @param Request $request
     * @param array<string, string|array<int, string>> $rules
     * @param Session|null $session
     * @param PDO|null $pdo
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules, ?Session $session = null, ?PDO $pdo = null): array
    {
        $data = $request->all();
        $db = $pdo;

        if ($db === null && property_exists($this, 'db')) {
            if ($this->db instanceof Connection) {
                $db = $this->db->getPdo();
            } elseif ($this->db instanceof PDO) {
                $db = $this->db;
            }
        }

        $validator = Validator::make($data, $rules, $db);

        if ($validator->fails()) {
            $sess = $session;
            if ($sess === null && property_exists($this, 'session') && $this->session instanceof Session) {
                $sess = $this->session;
            }

            if ($sess !== null) {
                $sess->flash('errors', $validator->errors());
                $sess->flash('old', $data);
                $sess->flash('error', $validator->first());
            }

            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }
}
