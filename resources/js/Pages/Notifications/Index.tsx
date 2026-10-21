import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  Bell, 
  CheckCheck, 
  Trash2, 
  Check, 
  ExternalLink, 
  Clock, 
  Filter, 
  Info, 
  AlertTriangle, 
  CheckCircle2, 
  AlertCircle,
  Key,
  Folder,
  Layers,
  ShieldCheck
} from 'lucide-react';

interface NotificationItem {
  id: number;
  tenant_id: number;
  user_id: number;
  type: 'info' | 'success' | 'warning' | 'danger';
  title: string;
  message: string;
  action_url: string | null;
  icon: string | null;
  read_at: string | null;
  created_at: string;
  is_read: boolean;
}

interface NotificationsPageProps {
  notifications: NotificationItem[];
  unreadCount: number;
  activeFilter: 'all' | 'unread';
}

export default function NotificationsIndex({
  notifications,
  unreadCount,
  activeFilter,
}: NotificationsPageProps) {
  const [currentFilter, setCurrentFilter] = useState<'all' | 'unread'>(activeFilter || 'all');
  const [processingId, setProcessingId] = useState<number | null>(null);

  const handleFilterChange = (filter: 'all' | 'unread') => {
    setCurrentFilter(filter);
    router.get('/notifications', { filter }, { preserveState: true });
  };

  const handleMarkAsRead = (id: number) => {
    setProcessingId(id);
    router.post(`/notifications/${id}/read`, {}, {
      preserveScroll: true,
      onFinish: () => setProcessingId(null),
    });
  };

  const handleMarkAllAsRead = () => {
    router.post('/notifications/read-all', {}, {
      preserveScroll: true,
    });
  };

  const handleDelete = (id: number) => {
    if (confirm('Apakah Anda yakin ingin menghapus notifikasi ini?')) {
      router.post(`/notifications/${id}/delete`, {}, {
        preserveScroll: true,
      });
    }
  };

  const getNotificationIcon = (item: NotificationItem) => {
    if (item.icon === 'key') return <Key className="w-4 h-4" />;
    if (item.icon === 'folder') return <Folder className="w-4 h-4" />;
    if (item.icon === 'shield') return <ShieldCheck className="w-4 h-4" />;
    if (item.icon === 'layers') return <Layers className="w-4 h-4" />;

    switch (item.type) {
      case 'success':
        return <CheckCircle2 className="w-4 h-4 text-emerald-500" />;
      case 'warning':
        return <AlertTriangle className="w-4 h-4 text-amber-500" />;
      case 'danger':
        return <AlertCircle className="w-4 h-4 text-rose-500" />;
      case 'info':
      default:
        return <Info className="w-4 h-4 text-blue-500" />;
    }
  };

  const getTypeStyle = (type: string, isRead: boolean) => {
    if (isRead) {
      return 'border-border-subtle bg-surface/50 opacity-80';
    }
    switch (type) {
      case 'success':
        return 'border-emerald-500/30 bg-emerald-500/5 dark:bg-emerald-500/10';
      case 'warning':
        return 'border-amber-500/30 bg-amber-500/5 dark:bg-amber-500/10';
      case 'danger':
        return 'border-rose-500/30 bg-rose-500/5 dark:bg-rose-500/10';
      case 'info':
      default:
        return 'border-blue-500/30 bg-blue-500/5 dark:bg-blue-500/10';
    }
  };

  const getBadgeStyle = (type: string) => {
    switch (type) {
      case 'success':
        return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
      case 'warning':
        return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
      case 'danger':
        return 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
      case 'info':
      default:
        return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20';
    }
  };

  return (
    <AppLayout title="Pusat Notifikasi">
      <Head title="Pusat Notifikasi - aio SaaS" />

      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header Section */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-surface p-6 rounded-2xl border border-border-subtle shadow-xs">
          <div>
            <div className="flex items-center gap-3">
              <div className="p-2.5 rounded-xl bg-brand-primary-light text-brand-primary border border-brand-primary/20 shadow-2xs">
                <Bell className="w-5 h-5" />
              </div>
              <div>
                <h2 className="text-xl font-bold text-text-main">Pusat Notifikasi</h2>
                <p className="text-xs text-text-muted mt-0.5">
                  Pantau riwayat pembaruan, peringatan keamanan, dan kolaborasi di workspace Anda.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {unreadCount > 0 && (
              <button
                onClick={handleMarkAllAsRead}
                className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold bg-surface-subtle border border-border-subtle hover:bg-surface text-text-main transition-colors shadow-2xs"
              >
                <CheckCheck className="w-4 h-4 text-brand-primary" />
                <span>Tandai Semua Dibaca</span>
              </button>
            )}
          </div>
        </div>

        {/* Filter Navigation Bar */}
        <div className="flex items-center justify-between border-b border-border-subtle pb-3">
          <div className="flex items-center gap-2">
            <button
              onClick={() => handleFilterChange('all')}
              className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
                currentFilter === 'all'
                  ? 'bg-brand-primary text-white shadow-xs'
                  : 'bg-surface text-text-muted hover:text-text-main border border-border-subtle'
              }`}
            >
              Semua Notifikasi ({notifications.length})
            </button>
            <button
              onClick={() => handleFilterChange('unread')}
              className={`inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
                currentFilter === 'unread'
                  ? 'bg-brand-primary text-white shadow-xs'
                  : 'bg-surface text-text-muted hover:text-text-main border border-border-subtle'
              }`}
            >
              <span>Belum Dibaca</span>
              {unreadCount > 0 && (
                <span className={`px-2 py-0.5 text-[10px] rounded-full font-bold ${
                  currentFilter === 'unread' ? 'bg-white/20 text-white' : 'bg-brand-primary text-white'
                }`}>
                  {unreadCount}
                </span>
              )}
            </button>
          </div>

          <div className="text-xs text-text-muted flex items-center gap-1.5">
            <Filter className="w-3.5 h-3.5" />
            <span>Filter: {currentFilter === 'all' ? 'Semua' : 'Belum Dibaca'}</span>
          </div>
        </div>

        {/* Notification Cards List */}
        <div className="space-y-3">
          {notifications.length === 0 ? (
            <div className="p-12 text-center rounded-2xl bg-surface border border-border-subtle">
              <div className="w-12 h-12 rounded-2xl bg-surface-subtle text-text-muted flex items-center justify-center mx-auto mb-3">
                <Bell className="w-6 h-6 opacity-60" />
              </div>
              <h3 className="text-sm font-bold text-text-main">Tidak Ada Notifikasi</h3>
              <p className="text-xs text-text-muted mt-1 max-w-sm mx-auto">
                {currentFilter === 'unread' 
                  ? 'Bagus sekali! Semua notifikasi Anda sudah dibaca.' 
                  : 'Belum ada notifikasi yang tercatat untuk workspace ini.'}
              </p>
            </div>
          ) : (
            notifications.map((item) => (
              <div
                key={item.id}
                className={`p-4 rounded-xl border transition-all duration-150 flex flex-col sm:flex-row sm:items-center justify-between gap-4 ${getTypeStyle(
                  item.type,
                  item.is_read
                )}`}
              >
                <div className="flex items-start gap-3.5 min-w-0">
                  <div className="mt-0.5 p-2 rounded-lg bg-surface border border-border-subtle shrink-0 shadow-2xs">
                    {getNotificationIcon(item)}
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h4 className={`text-sm font-semibold text-text-main ${!item.is_read ? 'font-bold' : ''}`}>
                        {item.title}
                      </h4>
                      <span className={`px-2 py-0.5 rounded-md text-[10px] uppercase font-bold border ${getBadgeStyle(item.type)}`}>
                        {item.type}
                      </span>
                      {!item.is_read && (
                        <span className="w-2 h-2 rounded-full bg-brand-primary shrink-0 animate-pulse" />
                      )}
                    </div>
                    <p className="text-xs text-text-muted mt-1 leading-relaxed">
                      {item.message}
                    </p>
                    <div className="flex items-center gap-3 mt-2 text-[11px] text-text-muted font-medium">
                      <span className="flex items-center gap-1">
                        <Clock className="w-3 h-3" />
                        {item.created_at}
                      </span>
                      {item.is_read && (
                        <span className="text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                          <Check className="w-3 h-3" />
                          Dibaca: {item.read_at}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2 shrink-0 self-end sm:self-center">
                  {item.action_url && (
                    <Link
                      href={item.action_url}
                      onClick={() => !item.is_read && handleMarkAsRead(item.id)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-brand-primary text-white hover:bg-brand-primary-hover shadow-2xs transition-colors"
                    >
                      <span>Buka</span>
                      <ExternalLink className="w-3 h-3" />
                    </Link>
                  )}

                  {!item.is_read && (
                    <button
                      onClick={() => handleMarkAsRead(item.id)}
                      disabled={processingId === item.id}
                      title="Tandai telah dibaca"
                      className="p-1.5 rounded-lg text-text-muted hover:text-brand-primary hover:bg-surface border border-border-subtle transition-colors"
                    >
                      <Check className="w-4 h-4" />
                    </button>
                  )}

                  <button
                    onClick={() => handleDelete(item.id)}
                    title="Hapus notifikasi"
                    className="p-1.5 rounded-lg text-text-muted hover:text-rose-500 hover:bg-rose-500/10 border border-border-subtle transition-colors"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </AppLayout>
  );
}
