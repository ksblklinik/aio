<?php

declare(strict_types=1);

namespace App\Modules\Contact\Controllers;

use App\Modules\Contact\Contracts\ContactRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;

class ApiContactController
{
    public function __construct(
        private ContactRegistryInterface $contactService
    ) {
    }

    public function search(Request $request): Response
    {
        $query = (string) $request->query('q', '');
        $role = (string) $request->query('role', '');
        $limit = (int) $request->query('limit', 20);

        if ($role !== '') {
            $contacts = $this->contactService->getByRole($role, ['limit' => $limit]);
        } else {
            $contacts = $this->contactService->search($query, $limit);
        }

        return Response::json([
            'status' => 'success',
            'data' => array_map(fn($c) => $c->toArray(), $contacts),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $contact = $this->contactService->find($id);

        if (!$contact) {
            return Response::json(['status' => 'error', 'message' => 'Contact not found'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $contact->toArray(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $request->json();
        if (empty($data['name'])) {
            return Response::json(['status' => 'error', 'message' => 'Name is required'], 422);
        }

        $contact = $this->contactService->create($data);

        return Response::json([
            'status' => 'success',
            'data' => $contact->toArray(),
        ], 201);
    }
}
