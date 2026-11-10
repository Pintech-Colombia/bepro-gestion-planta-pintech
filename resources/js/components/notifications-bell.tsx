import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { formatRelativeTime } from '@/components/formatted-date';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatBadgeCount, formatKind } from '@/lib/formatters';
import { index as alertsIndex } from '@/routes/alerts';
import {
    read as notificationsRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';

/**
 * Campana del encabezado, para todo usuario con sesión. Arriba, sus avisos personales sin leer (prop `notifications`):
 * cada uno se marca leído al abrirlo y lleva al pedido. Abajo, con `alerts.view`, las alertas sin resolver que ve
 * (`unresolvedAlertsCount` y `recentAlerts`, ya filtradas por tipo según sus permisos). El número suma las dos cosas.
 */
export function NotificationsBell() {
    const { auth, notifications, unresolvedAlertsCount, recentAlerts } =
        usePage().props;

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

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
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
            </DropdownMenuTrigger>
            {/* 320 px: cabe en un celular de 390 px con su margen. */}
            <DropdownMenuContent align="end" className="w-80">
                {unread.length > 0 && (
                    <>
                        <DropdownMenuLabel>Avisos</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {unread.map((notification) => {
                            const createdAgo = formatRelativeTime(
                                notification.created_at,
                            );

                            return (
                                <DropdownMenuItem
                                    key={notification.id}
                                    className="flex cursor-pointer flex-col items-start gap-1"
                                    // Lo marca leído y el servidor redirige al pedido.
                                    onSelect={() =>
                                        router.post(
                                            notificationsRead(notification.id)
                                                .url,
                                        )
                                    }
                                >
                                    <span className="line-clamp-3 text-sm text-foreground">
                                        {notification.message}
                                    </span>
                                    {createdAgo && (
                                        <span className="text-xs text-muted-foreground">
                                            {createdAgo}
                                        </span>
                                    )}
                                </DropdownMenuItem>
                            );
                        })}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="cursor-pointer justify-center font-medium"
                            onSelect={() =>
                                router.post(
                                    notificationsReadAll().url,
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            Marcar todos como leídos
                        </DropdownMenuItem>
                    </>
                )}

                {canViewAlerts && (
                    <>
                        {unread.length > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuLabel>
                            Alertas pendientes
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {recentAlerts.length === 0 ? (
                            <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                                No hay alertas pendientes
                            </p>
                        ) : (
                            recentAlerts.map((alert) => {
                                const createdAgo = formatRelativeTime(
                                    alert.created_at,
                                );

                                return (
                                    <DropdownMenuItem key={alert.id} asChild>
                                        <Link
                                            href={alertsIndex({
                                                query: { type: alert.type },
                                            })}
                                            className="flex cursor-pointer flex-col items-start gap-1"
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
                                    </DropdownMenuItem>
                                );
                            })
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link
                                href={alertsIndex()}
                                className="cursor-pointer justify-center font-medium"
                            >
                                Ver todas
                            </Link>
                        </DropdownMenuItem>
                    </>
                )}

                {!canViewAlerts && unread.length === 0 && (
                    <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                        No hay avisos pendientes
                    </p>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
