import { usePage } from '@inertiajs/react';
import type { PageProps } from '@inertiajs/core';

interface AuthPageProps extends PageProps {
  auth?: {
    user?: {
      id: number;
      name: string;
      email: string;
      is_superadmin: boolean;
    } | null;
    tenant?: {
      id: number;
      name: string;
      slug: string;
      plan: string;
    } | null;
    role?: string | null;
    is_superadmin?: boolean;
    permissions?: string[];
  };
}

/**
 * Custom React hook untuk mengecek apakah user aktif memiliki izin (permission) tertentu
 *
 * @param permission Nama kunci permission (contoh: 'projects.create', 'modules.manage')
 * @returns boolean
 */
export function useCan(permission: string): boolean {
  const { auth } = usePage<AuthPageProps>().props;

  if (!auth || !auth.user) {
    return false;
  }

  // Superadmin platform atau Owner workspace selalu memiliki akses tak terbatas
  if (auth.is_superadmin || auth.role === 'owner') {
    return true;
  }

  const permissions = auth.permissions || [];
  return permissions.includes('*') || permissions.includes(permission);
}
