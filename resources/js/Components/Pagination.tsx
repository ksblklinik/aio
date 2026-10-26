import { Link } from '@inertiajs/react';

export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginationProps {
  links: PaginationLink[];
  from?: number | null;
  to?: number | null;
  total?: number;
  className?: string;
}

export default function Pagination({
  links,
  from,
  to,
  total,
  className = '',
}: PaginationProps) {
  if (!links || links.length <= 3) {
    // Kurang dari 2 halaman (hanya previous, 1 halaman, dan next) -> tidak perlu render
    return null;
  }

  // Helper untuk decode label HTML seperti &laquo; dan &raquo;
  const cleanLabel = (label: string): string => {
    return label
      .replace('&laquo;', '«')
      .replace('&raquo;', '»')
      .replace('&lsaquo;', '‹')
      .replace('&rsaquo;', '›');
  };

  return (
    <div
      className={`pagination-container ${className}`}
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: '1rem',
        padding: '0.75rem 0',
        marginTop: '1.25rem',
      }}
    >
      {/* Summary Info */}
      {total !== undefined && from !== null && to !== null && from !== undefined && to !== undefined && (
        <div
          style={{
            fontSize: '0.875rem',
            color: 'var(--color-text-muted, #6b7280)',
          }}
        >
          Menampilkan <span style={{ fontWeight: 600, color: 'var(--color-text, #111827)' }}>{from}</span> sampai{' '}
          <span style={{ fontWeight: 600, color: 'var(--color-text, #111827)' }}>{to}</span> dari{' '}
          <span style={{ fontWeight: 600, color: 'var(--color-text, #111827)' }}>{total}</span> data
        </div>
      )}

      {/* Page Links */}
      <nav
        aria-label="Navigasi Halaman"
        style={{
          display: 'inline-flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          gap: '0.25rem',
        }}
      >
        {links.map((link, idx) => {
          const isCurrent = link.active;
          const isDisabled = !link.url;
          const label = cleanLabel(link.label);

          if (isDisabled) {
            return (
              <span
                key={idx}
                aria-disabled="true"
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  minWidth: '2.25rem',
                  height: '2.25rem',
                  padding: '0 0.5rem',
                  fontSize: '0.875rem',
                  borderRadius: '0.375rem',
                  color: 'var(--color-text-muted, #9ca3af)',
                  backgroundColor: 'transparent',
                  border: '1px solid var(--color-border, #e5e7eb)',
                  cursor: 'not-allowed',
                  opacity: 0.6,
                  userSelect: 'none',
                }}
              >
                {label}
              </span>
            );
          }

          return (
            <Link
              key={idx}
              href={link.url!}
              preserveScroll
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                minWidth: '2.25rem',
                height: '2.25rem',
                padding: '0 0.5rem',
                fontSize: '0.875rem',
                fontWeight: isCurrent ? 600 : 400,
                borderRadius: '0.375rem',
                color: isCurrent ? '#ffffff' : 'var(--color-text, #374151)',
                backgroundColor: isCurrent ? 'var(--color-primary, #3b82f6)' : 'var(--color-bg-card, #ffffff)',
                border: isCurrent ? '1px solid var(--color-primary, #3b82f6)' : '1px solid var(--color-border, #d1d5db)',
                textDecoration: 'none',
                transition: 'all 0.15s ease-in-out',
                boxShadow: isCurrent ? '0 1px 2px rgba(59, 130, 246, 0.3)' : 'none',
              }}
            >
              {label}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
