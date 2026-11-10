import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { formatRelativeTime } from '@/components/formatted-date';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatBadgeCount, formatKind } from '@/lib/formatters';
import { index as alertsIndex } from '@/routes/alerts';
import {
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';

type BellTab = 'notices' | 'alerts';

/** Clases de cada fila de la lista: un botón o enlace a todo el ancho, como las opciones de un menú. */
const ROW_CLASS =
    'flex w-full cursor-pointer flex-col items-start gap-1 rounded-sm px-2 py-2 text-left outline-hidden hover:bg-accent focus-visible:bg-accent';

/**
 * Campana del encabezado, para todo usuario con sesión. Dos pestañas, para que no se amontonen: «Avisos», sus avisos
 * personales sin leer (prop `notifications`; cada uno se marca leído al abrirlo y lleva al pedido), y «Alertas», solo
 * con `alerts.view`, las alertas sin resolver que ve (`unresolvedAlertsCount` y `recentAlerts`, ya filtradas por tipo
 * según sus permisos). Sin `alerts.view` no hay pestañas: solo los avisos. El número del icono suma las dos cosas.
 * Va en un `Popover` y no en un `DropdownMenu`: las pestañas no caben en el manejo de teclado de un menú.
 */
export function NotificationsBell() {
    const { auth, notifications, unresolvedAlertsCount, recentAlerts } =
        usePage().props;
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState<BellTab>('notices');

    if (!auth.user) {
        return null;
    }

    const canViewAlerts = auth.user.permissions?.includes('alerts.view');
    const unreadCount = notifications?.unread_count ?? 0;
    const unread = notifications?.items ?? [];
    const alertsCount = canViewAlerts ? unresolvedAlertsCount : 0;
    const total = unreadCount + alertsCount;

    // Los dos por separado: el número del icono los suma.
    const labelParts = [
        unreadCount > 0 &&
            `${formatKind(unreadCount, 'units')} ${unreadCount === 1 ? 'aviso sin leer' : 'avisos sin leer'}`,
        alertsCount > 0 &&
            `${formatKind(alertsCount, 'units')} ${alertsCount === 1 ? 'alerta sin resolver' : 'alertas sin resolver'}`,
    ].filter(Boolean);
    const ariaLabel =
        labelParts.length > 0
            ? `Avisos y alertas: ${labelParts.join(', ')}`
            : 'Avisos y alertas';

    // Cada vez que se abre, empieza en la pestaña que tiene algo nuevo: avisos primero.
    const handleOpenChange = (next: boolean) => {
        if (next) {
            setTab(unreadCount === 0 && alertsCount > 0 ? 'alerts' : 'notices');
        }

        setOpen(next);
    };

    const noticesPanel =
        unread.length === 0 ? (
            <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                No hay avisos sin leer
            </p>
        ) : (
            <>
                <ul className="max-h-80 overflow-y-auto">
                    {unread.map((notification) => {
                        const createdAgo = formatRelativeTime(
                            notification.created_at,
                        );

                        return (
                            <li key={notification.id}>
                                <button
                                    type="button"
                                    className={ROW_CLASS}
                                    // Lo marca leído y el servidor redirige al pedido.
                                    onClick={() => {
                                        setOpen(false);
                                        router.post(
                                            notificationsRead(notification.id)
                                                .url,
                                        );
                                    }}
                                >
                                    <span className="line-clamp-3 text-sm text-foreground">
                                        {notification.message}
                                    </span>
                                    {createdAgo && (
                                        <span className="text-xs text-muted-foreground">
                                            {createdAgo}
                                        </span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="mt-1 w-full"
                    onClick={() => {
                        setOpen(false);
                        router.post(
                            notificationsReadAll().url,
                            {},
                            { preserveScroll: true },
                        );
                    }}
                >
                    Marcar todos como leídos
                </Button>
            </>
        );

    const alertsPanel = (
        <>
            {recentAlerts.length === 0 ? (
                <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                    No hay alertas pendientes
                </p>
            ) : (
                <ul className="max-h-80 overflow-y-auto">
                    {recentAlerts.map((alert) => {
                        const createdAgo = formatRelativeTime(alert.created_at);

                        return (
                            <li key={alert.id}>
                                <Link
                                    href={alertsIndex({
                                        query: { type: alert.type },
                                    })}
                                    className={ROW_CLASS}
                                    onClick={() => setOpen(false)}
                                >
                                    <span className="flex items-center gap-2">
                                        <StatusBadge
                                            enum="AlertSeverity"
                                            value={alert.severity}
                                        />
                                        {createdAgo && (
                                            <span className="text-xs text-muted-foreground">
                                                {createdAgo}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-sm text-foreground">
                                        {alert.message}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
            <Button asChild variant="ghost" size="sm" className="mt-1 w-full">
                <Link href={alertsIndex()} onClick={() => setOpen(false)}>
                    Ver todas
                </Link>
            </Button>
        </>
    );

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="relative size-10 text-muted-foreground hover:text-foreground"
                    aria-label={ariaLabel}
                >
                    <Bell className="size-4" />
                    {total > 0 && (
                        <span
                            aria-hidden="true"
                            className="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-xs font-medium text-destructive-foreground tabular-nums ring-2 ring-background"
                        >
                            {formatBadgeCount(total)}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            {/* 320 px: cabe en un celular de 390 px con su margen. */}
            <PopoverContent align="end" className="w-80 p-2">
                {canViewAlerts ? (
                    <Tabs
                        value={tab}
                        onValueChange={(value) => setTab(value as BellTab)}
                    >
                        <TabsList className="w-full">
                            <TabsTrigger value="notices">
                                Avisos
                                {unreadCount > 0 &&
                                    ` (${formatBadgeCount(unreadCount)})`}
                            </TabsTrigger>
                            <TabsTrigger value="alerts">
                                Alertas
                                {alertsCount > 0 &&
                                    ` (${formatBadgeCount(alertsCount)})`}
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="notices">
                            {noticesPanel}
                        </TabsContent>
                        <TabsContent value="alerts">{alertsPanel}</TabsContent>
                    </Tabs>
                ) : (
                    noticesPanel
                )}
            </PopoverContent>
        </Popover>
    );
}
