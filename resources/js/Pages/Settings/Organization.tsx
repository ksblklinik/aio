import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  Building2, 
  Globe, 
  Coins, 
  Clock, 
  Save, 
  CheckCircle2, 
  ShieldCheck,
  Phone,
  MapPin,
  FileText
} from 'lucide-react';

interface SelectOption {
  value: string;
  label: string;
}

interface TenantProfile {
  id: number;
  name: string;
  slug: string;
  plan: string;
  org_type: string;
  timezone: string;
  currency: string;
  locale: string;
}

interface CustomSettings {
  company_address: string;
  company_phone: string;
  tax_number: string;
}

interface OrganizationProps {
  tenant: TenantProfile;
  custom_settings: CustomSettings;
  org_types: SelectOption[];
  currencies: SelectOption[];
  timezones: SelectOption[];
}

export default function Organization({
  tenant,
  custom_settings,
  org_types,
  currencies,
  timezones,
}: OrganizationProps) {
  const [savedAlert, setSavedAlert] = useState(false);

  const { data, setData, post, processing, errors } = useForm({
    name: tenant.name,
    org_type: tenant.org_type || 'general',
    timezone: tenant.timezone || 'UTC',
    currency: tenant.currency || 'USD',
    locale: tenant.locale || 'en_US',
    company_address: custom_settings.company_address || '',
    company_phone: custom_settings.company_phone || '',
    tax_number: custom_settings.tax_number || '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/settings/organization', {
      onSuccess: () => {
        setSavedAlert(true);
        setTimeout(() => setSavedAlert(false), 4000);
      },
    });
  };

  return (
    <AppLayout title="Pengaturan Organisasi">
      <Head title="Pengaturan Profil Organisasi" />

      <div style={{ maxWidth: '960px', margin: '0 auto', padding: '1.5rem' }}>
        {/* Header Banner */}
        <div style={{ marginBottom: '2rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '0.5rem' }}>
            <div style={{ 
              padding: '0.5rem', 
              borderRadius: '0.5rem', 
              backgroundColor: 'var(--color-primary-subtle, rgba(59, 130, 246, 0.1))',
              color: 'var(--color-primary, #3b82f6)' 
            }}>
              <Building2 size={24} />
            </div>
            <div>
              <h1 style={{ fontSize: '1.5rem', fontWeight: 700, margin: 0, color: 'var(--color-text, #111827)' }}>
                Profil & Konfigurasi Organisasi
              </h1>
              <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--color-text-muted, #6b7280)' }}>
                Kelola tipe entitas, format mata uang, zona waktu, dan metadata identitas untuk workspace {tenant.name}.
              </p>
            </div>
          </div>
        </div>

        {savedAlert && (
          <div style={{
            display: 'flex',
            alignItems: 'center',
            gap: '0.75rem',
            padding: '1rem',
            marginBottom: '1.5rem',
            borderRadius: '0.5rem',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            border: '1px solid rgba(16, 185, 129, 0.2)',
            color: '#059669',
            fontSize: '0.875rem',
            fontWeight: 500
          }}>
            <CheckCircle2 size={18} />
            <span>Perubahan profil organisasi dan pengaturan regional berhasil disimpan!</span>
          </div>
        )}

        <form onSubmit={handleSubmit}>
          {/* Main Card: Core Entity Profile */}
          <div style={{
            backgroundColor: 'var(--color-bg-card, #ffffff)',
            borderRadius: '0.75rem',
            border: '1px solid var(--color-border, #e5e7eb)',
            padding: '1.5rem',
            marginBottom: '1.5rem',
            boxShadow: 'var(--shadow-sm, 0 1px 2px 0 rgba(0, 0, 0, 0.05))'
          }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 600, margin: '0 0 1.25rem 0', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <ShieldCheck size={18} color="var(--color-primary, #3b82f6)" />
              Identitas Entitas Workspace
            </h2>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.25rem' }}>
              {/* Workspace Name */}
              <div>
                <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  Nama Organisasi / Bisnis <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  value={data.name}
                  onChange={e => setData('name', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: errors.name ? '1px solid #ef4444' : '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                  placeholder="Contoh: Klinik Harapan Sehat / PT Maju Sentosa"
                  required
                />
                {errors.name && <p style={{ color: '#ef4444', fontSize: '0.75rem', marginTop: '0.25rem' }}>{errors.name}</p>}
              </div>

              {/* Entity Type */}
              <div>
                <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  Tipe Entitas / Domain Bisnis <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <select
                  value={data.org_type}
                  onChange={e => setData('org_type', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                >
                  {org_types.map(type => (
                    <option key={type.value} value={type.value}>{type.label}</option>
                  ))}
                </select>
                <p style={{ fontSize: '0.75rem', color: 'var(--color-text-muted, #6b7280)', marginTop: '0.25rem' }}>
                  Menentukan adaptasi terminologi dan fitur modul universal yang sesuai.
                </p>
              </div>
            </div>
          </div>

          {/* Regional & Localization Settings Card */}
          <div style={{
            backgroundColor: 'var(--color-bg-card, #ffffff)',
            borderRadius: '0.75rem',
            border: '1px solid var(--color-border, #e5e7eb)',
            padding: '1.5rem',
            marginBottom: '1.5rem',
            boxShadow: 'var(--shadow-sm, 0 1px 2px 0 rgba(0, 0, 0, 0.05))'
          }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 600, margin: '0 0 1.25rem 0', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <Globe size={18} color="var(--color-primary, #3b82f6)" />
              Preferensi Regional & Format Finansial
            </h2>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.25rem' }}>
              {/* Currency */}
              <div>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.375rem', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  <Coins size={14} />
                  Mata Uang Default
                </label>
                <select
                  value={data.currency}
                  onChange={e => setData('currency', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                >
                  {currencies.map(c => (
                    <option key={c.value} value={c.value}>{c.label}</option>
                  ))}
                </select>
                <p style={{ fontSize: '0.75rem', color: 'var(--color-text-muted, #6b7280)', marginTop: '0.25rem' }}>
                  Format angka keuangan akan diformat seragam via helper format_currency() / money().
                </p>
              </div>

              {/* Timezone */}
              <div>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.375rem', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  <Clock size={14} />
                  Zona Waktu Operasional
                </label>
                <select
                  value={data.timezone}
                  onChange={e => setData('timezone', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                >
                  {timezones.map(tz => (
                    <option key={tz.value} value={tz.value}>{tz.label}</option>
                  ))}
                </select>
                <p style={{ fontSize: '0.75rem', color: 'var(--color-text-muted, #6b7280)', marginTop: '0.25rem' }}>
                  Waktu audit log, nota transaksi, dan penjadwalan dikonversi ke zona waktu ini.
                </p>
              </div>

              {/* Locale */}
              <div>
                <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  Kode Bahasa / Locale
                </label>
                <select
                  value={data.locale}
                  onChange={e => setData('locale', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                >
                  <option value="id_ID">Bahasa Indonesia (id_ID)</option>
                  <option value="en_US">English - United States (en_US)</option>
                  <option value="en_GB">English - United Kingdom (en_GB)</option>
                </select>
              </div>
            </div>
          </div>

          {/* Legal & Contact Info Card */}
          <div style={{
            backgroundColor: 'var(--color-bg-card, #ffffff)',
            borderRadius: '0.75rem',
            border: '1px solid var(--color-border, #e5e7eb)',
            padding: '1.5rem',
            marginBottom: '1.5rem',
            boxShadow: 'var(--shadow-sm, 0 1px 2px 0 rgba(0, 0, 0, 0.05))'
          }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 600, margin: '0 0 1.25rem 0', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <FileText size={18} color="var(--color-primary, #3b82f6)" />
              Informasi Kontak & Legalitas Faktur
            </h2>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1.25rem', marginBottom: '1.25rem' }}>
              <div>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.375rem', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  <Phone size={14} />
                  Nomor Telepon Kantor / Hotline
                </label>
                <input
                  type="text"
                  value={data.company_phone}
                  onChange={e => setData('company_phone', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                  placeholder="+62 21 555-0199"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                  Nomor Pokok Wajib Pajak / Tax ID / NPWP
                </label>
                <input
                  type="text"
                  value={data.tax_number}
                  onChange={e => setData('tax_number', e.target.value)}
                  style={{
                    width: '100%',
                    padding: '0.625rem 0.75rem',
                    borderRadius: '0.375rem',
                    border: '1px solid var(--color-border, #d1d5db)',
                    backgroundColor: 'var(--color-bg-input, #ffffff)',
                    color: 'var(--color-text, #111827)',
                    fontSize: '0.875rem'
                  }}
                  placeholder="01.234.567.8-901.000"
                />
              </div>
            </div>

            <div>
              <label style={{ display: 'flex', alignItems: 'center', gap: '0.375rem', fontSize: '0.875rem', fontWeight: 500, marginBottom: '0.375rem', color: 'var(--color-text, #374151)' }}>
                <MapPin size={14} />
                Alamat Lengkap Kantor / Entitas
              </label>
              <textarea
                value={data.company_address}
                onChange={e => setData('company_address', e.target.value)}
                rows={3}
                style={{
                  width: '100%',
                  padding: '0.625rem 0.75rem',
                  borderRadius: '0.375rem',
                  border: '1px solid var(--color-border, #d1d5db)',
                  backgroundColor: 'var(--color-bg-input, #ffffff)',
                  color: 'var(--color-text, #111827)',
                  fontSize: '0.875rem',
                  fontFamily: 'inherit',
                  resize: 'vertical'
                }}
                placeholder="Jl. Sudirman No. 123, Jakarta Pusat, DKI Jakarta 10220"
              />
            </div>
          </div>

          {/* Action Bar */}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
            <button
              type="submit"
              disabled={processing}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '0.5rem',
                padding: '0.625rem 1.25rem',
                borderRadius: '0.375rem',
                backgroundColor: 'var(--color-primary, #3b82f6)',
                color: '#ffffff',
                border: 'none',
                fontSize: '0.875rem',
                fontWeight: 600,
                cursor: processing ? 'not-allowed' : 'pointer',
                opacity: processing ? 0.7 : 1,
                boxShadow: 'var(--shadow-sm, 0 1px 2px 0 rgba(0, 0, 0, 0.05))'
              }}
            >
              <Save size={16} />
              {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
            </button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
