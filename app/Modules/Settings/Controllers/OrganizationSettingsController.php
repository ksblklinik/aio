<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Settings\TenantSettings;
use Core\Validation\Validator;

class OrganizationSettingsController
{
    public function __construct(
        protected AuthService $auth,
        protected Inertia $inertia,
        protected Session $session,
        protected TenantSettings $settings
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        if (!$tenant || !$tenant->id) {
            return Response::redirect('/dashboard');
        }

        $profile = $this->settings->getTenantProfile((int) $tenant->id);
        $customSettings = $this->settings->all('core', (int) $tenant->id);

        return $this->inertia->render('Settings/Organization', [
            'tenant' => [
                'id' => $profile['id'],
                'name' => $profile['name'],
                'slug' => $profile['slug'],
                'plan' => $profile['plan'],
                'org_type' => $profile['org_type'],
                'timezone' => $profile['timezone'],
                'currency' => $profile['currency'],
                'locale' => $profile['locale'],
            ],
            'custom_settings' => [
                'company_address' => $customSettings['company_address'] ?? '',
                'company_phone' => $customSettings['company_phone'] ?? '',
                'tax_number' => $customSettings['tax_number'] ?? '',
            ],
            'org_types' => [
                ['value' => 'general', 'label' => 'Umum / Standar'],
                ['value' => 'for_profit', 'label' => 'Bisnis & Perusahaan (Profit)'],
                ['value' => 'retail', 'label' => 'Toko / Retail & POS'],
                ['value' => 'clinic', 'label' => 'Klinik & Layanan Medis'],
                ['value' => 'school', 'label' => 'Sekolah & Institusi Pendidikan'],
                ['value' => 'non_profit', 'label' => 'Yayasan, Donasi & Non-Profit'],
            ],
            'currencies' => [
                ['value' => 'IDR', 'label' => 'IDR - Rupiah Indonesia (Rp)'],
                ['value' => 'USD', 'label' => 'USD - US Dollar ($)'],
                ['value' => 'EUR', 'label' => 'EUR - Euro (€)'],
                ['value' => 'SGD', 'label' => 'SGD - Singapore Dollar (S$)'],
                ['value' => 'MYR', 'label' => 'MYR - Malaysian Ringgit (RM)'],
            ],
            'timezones' => [
                ['value' => 'Asia/Jakarta', 'label' => 'WIB - Asia/Jakarta (UTC+7)'],
                ['value' => 'Asia/Makassar', 'label' => 'WITA - Asia/Makassar (UTC+8)'],
                ['value' => 'Asia/Jayapura', 'label' => 'WIT - Asia/Jayapura (UTC+9)'],
                ['value' => 'UTC', 'label' => 'UTC - Universal Coordinated Time'],
                ['value' => 'America/New_York', 'label' => 'EST - New York (UTC-5)'],
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        if (!$tenant || !$tenant->id) {
            return Response::redirect('/dashboard');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3',
            'org_type' => 'required|in:general,for_profit,retail,clinic,school,non_profit',
            'timezone' => 'required',
            'currency' => 'required',
            'locale' => 'required',
        ]);

        if ($validator->fails()) {
            $this->session->flash('error', $validator->first() ?? 'Data profil organisasi tidak valid.');
            return Response::redirect('/settings/organization');
        }

        $data = $validator->validated();

        $this->settings->updateTenantProfile([
            'name' => (string) $data['name'],
            'org_type' => (string) $data['org_type'],
            'timezone' => (string) $data['timezone'],
            'currency' => (string) $data['currency'],
            'locale' => (string) $data['locale'],
        ], (int) $tenant->id);

        // Update optional custom settings
        if ($request->has('company_address')) {
            $this->settings->set('company_address', (string) $request->input('company_address'), 'core', (int) $tenant->id);
        }
        if ($request->has('company_phone')) {
            $this->settings->set('company_phone', (string) $request->input('company_phone'), 'core', (int) $tenant->id);
        }
        if ($request->has('tax_number')) {
            $this->settings->set('tax_number', (string) $request->input('tax_number'), 'core', (int) $tenant->id);
        }

        $this->session->flash('success', 'Profil dan pengaturan organisasi berhasil diperbarui.');
        return Response::redirect('/settings/organization');
    }
}
