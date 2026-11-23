import { Search, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetBody,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { countActiveFilters, hasActiveFilters } from '@/lib/table-filters';
import type { FilterField, FilterValues } from '@/lib/table-filters';
import { cn } from '@/lib/utils';

export type { FilterField };

type DataTableFiltersProps = {
    fields: FilterField[];
    filters: FilterValues;
    defaultFilters?: FilterValues;
    onFilter: (
        keyOrUpdates: string | Record<string, string | undefined>,
        value?: string | undefined,
    ) => void;
    onFilterImmediate?: (
        keyOrUpdates: string | Record<string, string | undefined>,
        value?: string | undefined,
    ) => void;
    onClear: () => void;
};

function filterFieldKey(field: FilterField): string {
    return field.type === 'date-range'
        ? `${field.nameFrom}-${field.nameTo}`
        : field.name;
}

export function DataTableFilters({
    fields,
    filters,
    defaultFilters,
    onFilter,
    onFilterImmediate,
    onClear,
}: DataTableFiltersProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const isMobile = useIsMobile();
    const handleImmediate = onFilterImmediate ?? onFilter;

    const textFields = fields.filter(
        (field): field is Extract<FilterField, { type: 'text' }> =>
            field.type === 'text',
    );
    const secondaryFields = fields.filter((field) => field.type !== 'text');

    const anyFilterActive = hasActiveFilters(filters, defaultFilters, fields);
    const activeSecondaryCount = countActiveFilters(
        secondaryFields,
        filters,
        defaultFilters,
    );

    const renderSecondaryField = (field: FilterField, inSheet: boolean) => {
        if (field.type === 'select') {
            const allItemValue = field.allValue ?? '__all__';
            const rawValue = filters[field.name] ?? '';
            const selectValue =
                rawValue === '__all__' && !field.allValue ? '' : rawValue;

            return (
                <Select
                    key={field.name}
                    value={selectValue}
                    onValueChange={(value) => {
                        const resolvedValue =
                            value === '__all__'
                                ? (defaultFilters?.[field.name] ?? '')
                                : value;

                        handleImmediate(field.name, resolvedValue);
                    }}
                >
                    <SelectTrigger
                        aria-label={field.label}
                        className={inSheet ? 'w-full' : 'w-full md:w-52'}
                    >
                        <SelectValue placeholder={field.label} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={allItemValue}>Todos</SelectItem>
                        {field.options
                            .filter((opt) => opt.value !== '')
                            .map((opt) => (
                                <SelectItem key={opt.value} value={opt.value}>
                                    {opt.label}
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
            );
        }

        if (field.type === 'date-range') {
            return (
                <DateRangePicker
                    key={`${field.nameFrom}-${field.nameTo}`}
                    valueFrom={filters[field.nameFrom]}
                    valueTo={filters[field.nameTo]}
                    label={field.label}
                    onChange={(from, to) => {
                        handleImmediate({
                            [field.nameFrom]: from,
                            [field.nameTo]: to,
                        });
                    }}
                    className={inSheet ? 'w-full' : 'w-full md:w-56'}
                />
            );
        }

        return null;
    };

    return (
        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
            {(textFields.length > 0 || secondaryFields.length > 0) && (
                <div className="flex items-center gap-2 md:contents">
                    {textFields.map((field) => (
                        <div
                            key={field.name}
                            className="relative min-w-0 flex-1 md:min-w-56"
                        >
                            <Search className="absolute top-2.5 left-3 h-4 w-4 text-muted-foreground" />
                            <Input
                                aria-label={field.label}
                                placeholder={field.placeholder ?? field.label}
                                value={filters[field.name] ?? ''}
                                onChange={(e) =>
                                    onFilter(field.name, e.target.value)
                                }
                                className="pl-9"
                            />
                        </div>
                    ))}

                    {/* En el celular, los filtros secundarios van en un cajón inferior (DESIGN.md §9). */}
                    {secondaryFields.length > 0 && (
                        <Sheet
                            open={isMobile && sheetOpen}
                            onOpenChange={setSheetOpen}
                        >
                            <SheetTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="shrink-0 md:hidden"
                                >
                                    {activeSecondaryCount > 0
                                        ? `Filtros (${activeSecondaryCount})`
                                        : 'Filtros'}
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="bottom" className="md:hidden">
                                <SheetHeader>
                                    <SheetTitle>Filtros</SheetTitle>
                                    <SheetDescription className="sr-only">
                                        Filtra los resultados del listado.
                                    </SheetDescription>
                                </SheetHeader>
                                <SheetBody className="space-y-4">
                                    {secondaryFields.map((field) => (
                                        <div
                                            key={filterFieldKey(field)}
                                            className="space-y-1.5"
                                        >
                                            <p className="text-sm font-medium text-foreground">
                                                {field.label}
                                            </p>
                                            {renderSecondaryField(field, true)}
                                        </div>
                                    ))}
                                </SheetBody>
                                <SheetFooter className="flex-row items-center justify-between gap-2">
                                    {anyFilterActive ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                onClear();
                                                setSheetOpen(false);
                                            }}
                                        >
                                            Quitar filtros
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                    <Button
                                        type="button"
                                        onClick={() => setSheetOpen(false)}
                                    >
                                        Listo
                                    </Button>
                                </SheetFooter>
                            </SheetContent>
                        </Sheet>
                    )}
                </div>
            )}

            {/* Desde md: todos los filtros secundarios van en línea, igual que en escritorio. */}
            {secondaryFields.length > 0 && (
                <div className="hidden md:contents">
                    {secondaryFields.map((field) =>
                        renderSecondaryField(field, false),
                    )}
                </div>
            )}

            {anyFilterActive && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onClear}
                    className={cn(
                        'self-start text-muted-foreground md:self-auto',
                        secondaryFields.length > 0 && 'hidden md:inline-flex',
                    )}
                >
                    <X className="size-4" />
                    Quitar filtros
                </Button>
            )}
        </div>
    );
}
