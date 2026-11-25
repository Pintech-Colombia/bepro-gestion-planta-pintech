import { Head } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';

import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DataTableFilters } from '@/components/data-table-filters';
import { DetailItem, DetailList } from '@/components/detail-list';
import { EmptyCell } from '@/components/empty-cell';
import { FormattedDate } from '@/components/formatted-date';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetBody,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFilters } from '@/hooks/use-filters';
import { hasActiveFilters } from '@/lib/table-filters';
import { pillClasses, toneClasses } from '@/lib/tones';
import type { Tone } from '@/types/enums';
import type { Paginated } from '@/types/ui';

/**
 * Excepción documentada (DESIGN.md §4): los eventos de auditoría (`created`, `updated`, etc.)
 * provienen de spatie/laravel-activitylog y no son un enum propio de backend. Se mapean aquí
 * a tokens semánticos de Tone usando `lib/tones.ts`.
 */
const eventTones: Record<string, Tone> = {
    created: 'success',
    updated: 'info',
    deleted: 'danger',
    failed_login: 'warning',
    role_changed: 'info',
    role_created: 'success',
    role_updated: 'info',
    role_deleted: 'danger',
};

type Causer = {
    id: number;
    name: string;
    email: string;
};

/**
 * Un cambio ya en español y formateado en el servidor (`AuditLogPresenter`). `changed` tiene antes y después; `set`,
 * solo el valor nuevo (al crear, o un dato de un registro manual); `unset`, solo el anterior (al eliminar).
 */
type AuditChange = {
    field: string;
    label: string;
    before: string | null;
    after: string | null;
    kind: 'changed' | 'set' | 'unset';
};

type ActivityLog = {
    id: number;
    description: string;
    event: string;
    eventLabel: string;
    module: string;
    causer: Causer | null;
    /** `changes`: lo que cambió en un registro; `properties`: los datos de un registro manual (sesión, roles). */
    detailType: 'changes' | 'properties';
    changes: AuditChange[];
    createdAt: string;
};

type Option = { value: string; label: string };

type Props = {
    logs: Paginated<ActivityLog>;
    filters: {
        search?: string;
        log_name?: string;
        event?: string;
        date_from?: string;
        date_to?: string;
    };
    options: {
        logNames: Option[];
        events: Option[];
    };
};

function EventPill({ log }: { log: ActivityLog }) {
    return (
        <span
            className={`${pillClasses} ${toneClasses[eventTones[log.event] ?? 'neutral']}`}
        >
            {log.eventLabel}
        </span>
    );
}

function detailButtonLabel(log: ActivityLog): string {
    const count = log.changes.length;

    if (log.detailType === 'properties') {
        return `${count} ${count === 1 ? 'dato' : 'datos'} · Ver`;
    }

    return `${count} ${count === 1 ? 'cambio' : 'cambios'} · Ver`;
}

function ChangeValue({
    value,
    muted = false,
}: {
    value: string | null;
    muted?: boolean;
}) {
    if (value === null) {
        return <EmptyCell />;
    }

    return (
        <span
            className={
                muted
                    ? 'break-words text-muted-foreground'
                    : 'break-words text-foreground'
            }
        >
            {value}
        </span>
    );
}

/** Cada cambio en dos líneas: el campo y, debajo, «antes → después». */
function ChangeList({ changes }: { changes: AuditChange[] }) {
    return (
        <ul className="divide-y divide-border">
            {changes.map((change) => (
                <li key={change.field} className="py-3 text-sm">
                    <div className="text-xs font-medium text-muted-foreground">
                        {change.label}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                        {change.kind === 'changed' && (
                            <>
                                <ChangeValue value={change.before} muted />
                                <ArrowRight
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                    aria-label="pasó a"
                                />
                                <ChangeValue value={change.after} />
                            </>
                        )}
                        {change.kind === 'set' && (
                            <ChangeValue value={change.after} />
                        )}
                        {change.kind === 'unset' && (
                            <ChangeValue value={change.before} muted />
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** El registro queda montado mientras el panel se cierra: si se quitara al cerrar, se vaciaría en plena animación. */
function AuditLogDetailSheet({
    log,
    open,
    onOpenChange,
}: {
    log: ActivityLog | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-lg">
                {log && (
                    <>
                        <SheetHeader>
                            <SheetTitle>Detalle del registro</SheetTitle>
                            <SheetDescription>
                                {log.description}
                            </SheetDescription>
                        </SheetHeader>
                        <SheetBody className="space-y-6 pb-6">
                            <DetailList columns={2}>
                                <DetailItem
                                    label="Quién"
                                    value={
                                        log.causer ? (
                                            <>
                                                <div>{log.causer.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {log.causer.email}
                                                </div>
                                            </>
                                        ) : (
                                            'Sistema / Anónimo'
                                        )
                                    }
                                />
                                <DetailItem
                                    label="Cuándo"
                                    value={
                                        <FormattedDate
                                            value={log.createdAt}
                                            format="datetime"
                                        />
                                    }
                                />
                                <DetailItem label="Módulo" value={log.module} />
                                <DetailItem
                                    label="Evento"
                                    value={<EventPill log={log} />}
                                />
                            </DetailList>

                            <section className="space-y-1">
                                <h3 className="text-sm font-semibold">
                                    {log.detailType === 'properties'
                                        ? 'Datos registrados'
                                        : 'Cambios'}
                                </h3>
                                <ChangeList changes={log.changes} />
                            </section>
                        </SheetBody>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

export default function AuditLogsIndex({ logs, filters, options }: Props) {
    const {
        filters: filterState,
        setFilter,
        setFilterImmediate,
        clearFilters,
    } = useFilters({
        routeUrl: AuditLogController.index.url(),
        initialFilters: {
            search: filters.search ?? '',
            log_name: filters.log_name ?? '',
            event: filters.event ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        },
    });

    const [selectedLog, setSelectedLog] = useState<ActivityLog | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);

    const openDetail = (log: ActivityLog) => {
        setSelectedLog(log);
        setDetailOpen(true);
    };

    const filterFields: ComponentProps<typeof DataTableFilters>['fields'] = [
        {
            type: 'text',
            name: 'search',
            label: 'Buscar',
            placeholder: 'Usuario, descripción, número de OP...',
        },
        {
            type: 'select',
            name: 'log_name',
            label: 'Módulo',
            options: options.logNames,
        },
        {
            type: 'select',
            name: 'event',
            label: 'Evento',
            options: options.events,
        },
        {
            type: 'date-range',
            nameFrom: 'date_from',
            nameTo: 'date_to',
            label: 'Fecha',
        },
    ];

    // Sin página de detalle: la fecha identifica el evento y el botón de Detalles abre el panel con todo.
    const columns: DataListingColumn<ActivityLog>[] = [
        {
            key: 'created_at',
            header: 'Fecha',
            cell: (log) => (
                <FormattedDate value={log.createdAt} format="datetime" />
            ),
            mobile: 'title',
            className: 'whitespace-nowrap',
        },
        {
            key: 'causer',
            header: 'Causante',
            cell: (log) =>
                log.causer ? (
                    <div>
                        <div className="font-medium">{log.causer.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {log.causer.email}
                        </div>
                    </div>
                ) : (
                    <span className="text-muted-foreground italic">
                        Sistema / Anónimo
                    </span>
                ),
            mobile: 'meta',
        },
        {
            key: 'log_name',
            header: 'Módulo',
            cell: (log) => log.module,
            mobile: 'meta',
            className: 'whitespace-nowrap',
        },
        {
            key: 'event',
            header: 'Evento',
            cell: (log) => <EventPill log={log} />,
            mobile: 'status',
            align: 'center',
        },
        {
            key: 'description',
            header: 'Descripción',
            cell: (log) => log.description,
            mobile: 'subtitle',
            // Es lo principal de la fila: se lee entero, salta de línea.
            className: 'min-w-64 whitespace-normal',
        },
        {
            key: 'changes',
            header: 'Detalles',
            cell: (log) =>
                log.changes.length > 0 ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => openDetail(log)}
                    >
                        {detailButtonLabel(log)}
                    </Button>
                ) : null,
            mobile: 'meta',
            className: 'whitespace-nowrap',
        },
    ];

    return (
        <>
            <Head title="Auditoría" />

            <div className="space-y-6 p-6">
                <PageHeader
                    title="Auditoría"
                    description="Auditoría de eventos, cambios de sistema y accesos."
                />

                <DataTableFilters
                    fields={filterFields}
                    filters={filterState}
                    onFilter={setFilter}
                    onFilterImmediate={setFilterImmediate}
                    onClear={clearFilters}
                />

                <DataListing
                    rows={logs.data}
                    rowKey={(log) => log.id}
                    columns={columns}
                    empty="No hay registros de auditoría."
                    hasFilters={hasActiveFilters(filterState)}
                    onClearFilters={clearFilters}
                />

                <Pagination paginator={logs} />
            </div>

            <AuditLogDetailSheet
                log={selectedLog}
                open={detailOpen}
                onOpenChange={setDetailOpen}
            />
        </>
    );
}
