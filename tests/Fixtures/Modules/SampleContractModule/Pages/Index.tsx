import React from 'react';
import AppLayout from '@/Layouts/AppLayout';
import DynamicIcon from '@/Components/DynamicIcon';

export default function Index() {
  return (
    <AppLayout title="Sample Contract">
      <div className="p-4 bg-surface text-text-main">
        <DynamicIcon name="Box" size={24} />
        <h1>Sample Autonomous Module</h1>
      </div>
    </AppLayout>
  );
}
