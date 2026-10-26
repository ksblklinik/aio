import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
  resolve: (name) => {
    const defaultPages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
    const modulePages = import.meta.glob('../../app/Modules/**/Pages/**/*.tsx', { eager: true });

    // 1. Check default core pages directory (e.g. 'Dashboard', 'Projects/Index')
    if (defaultPages[`./Pages/${name}.tsx`]) {
      return defaultPages[`./Pages/${name}.tsx`];
    }

    // 2. Check namespaced module syntax (e.g. 'Projects::Index' or 'Donations::Reports/Annual')
    if (name.includes('::')) {
      const [moduleName, pagePath] = name.split('::');
      const cleanPath = pagePath.replace(/^Pages\//, '');
      const moduleKey = `../../app/Modules/${moduleName}/Pages/${cleanPath}.tsx`;
      if (modulePages[moduleKey]) {
        return modulePages[moduleKey];
      }
    }

    // 3. Check slash module syntax (e.g. 'Projects/Index' when co-located in app/Modules/Projects/Pages/Index.tsx)
    const slashIndex = name.indexOf('/');
    if (slashIndex !== -1) {
      const moduleName = name.slice(0, slashIndex);
      const subPath = name.slice(slashIndex + 1);
      const moduleKey = `../../app/Modules/${moduleName}/Pages/${subPath}.tsx`;
      if (modulePages[moduleKey]) {
        return modulePages[moduleKey];
      }
    }

    throw new Error(`Halaman Inertia [${name}] tidak ditemukan di direktori default maupun modul terdaftar.`);
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});
