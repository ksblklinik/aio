<?php

declare(strict_types=1);

namespace App\Modules\Contact\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Contact\Contracts\ContactRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;

class ContactController
{
    public function __construct(
        private AuthService $auth,
        private ContactRegistryInterface $contactService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $query = (string) $request->query('q', '');
        $roleFilter = (string) $request->query('role', '');

        if ($query !== '') {
            $contacts = $this->contactService->search($query);
        } elseif ($roleFilter !== '') {
            $contacts = $this->contactService->getByRole($roleFilter);
        } else {
            $contacts = $this->contactService->search('', limit: 50);
        }

        $contactList = array_map(fn($c) => $c->toArray(), $contacts);

        if ($this->inertia) {
            return $this->inertia->render('Contacts/Index', [
                'contacts' => $contactList,
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
                'filters' => [
                    'q' => $query,
                    'role' => $roleFilter,
                ],
            ]);
        }

        return Response::json(['contacts' => $contactList]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->session->flash('error', 'Nama kontak wajib diisi.');
            return Response::redirect('/contacts');
        }

        $type = (string) $request->input('type', 'individual');
        $rolesInput = $request->input('roles', []);
        $roles = is_array($rolesInput) ? $rolesInput : array_filter(explode(',', (string) $rolesInput));

        $attributes = [
            'type' => $type,
            'name' => $name,
            'legal_name' => $request->input('legal_name'),
            'id_number' => $request->input('id_number'),
            'tax_id' => $request->input('tax_id'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'mobile' => $request->input('mobile'),
            'notes' => $request->input('notes'),
            'roles' => $roles,
        ];

        $contact = $this->contactService->create($attributes);
        $this->session->flash('success', "Kontak {$contact->name} ({$contact->code}) berhasil ditambahkan.");

        return Response::redirect('/contacts');
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? $request->input('id', 0));
        if ($id <= 0) {
            return Response::redirect('/contacts');
        }

        $attributes = array_filter([
            'name' => $request->input('name'),
            'legal_name' => $request->input('legal_name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'mobile' => $request->input('mobile'),
            'status' => $request->input('status'),
            'notes' => $request->input('notes'),
            'id_number' => $request->input('id_number'),
            'tax_id' => $request->input('tax_id'),
        ], fn($v) => $v !== null);

        $this->contactService->update($id, $attributes);
        $this->session->flash('success', "Data kontak berhasil diperbarui.");

        return Response::redirect('/contacts');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? $request->input('id', 0));
        if ($id > 0) {
            $this->contactService->delete($id);
            $this->session->flash('success', 'Kontak berhasil diarsipkan.');
        }

        return Response::redirect('/contacts');
    }
}
