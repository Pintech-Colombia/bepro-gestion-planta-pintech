import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { toPlantDateTimeInput } from '@/lib/date-time-helpers';
import { cn } from '@/lib/utils';
import type {
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderSetData,
} from '@/types/production-orders';

type TimeField =
    | 'agitation_start_time'
    | 'agitation_end_time'
    | 'packaging_start_time'
    | 'packaging_end_time';

const TIME_FIELDS: { field: TimeField; label: string }[] = [
    { field: 'agitation_start_time', label: 'Inicio de agitación' },
    { field: 'agitation_end_time', label: 'Fin de agitación' },
    { field: 'packaging_start_time', label: 'Inicio de envasado' },
    { field: 'packaging_end_time', label: 'Fin de envasado' },
];

type OpClosingSectionProps = {
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    isReadOnly: boolean;
};

/**
 * Sección 5 de la OP: responsable y observaciones. Las cuatro horas casi nunca se llenan: van plegadas en «Más datos»,
 * que se abre solo si alguna tiene valor o error (así el foco llega al campo con error).
 */
export function OpClosingSection({
    data,
    setData,
    errors,
    isReadOnly,
}: OpClosingSectionProps) {
    const hasTimeValues = TIME_FIELDS.some(({ field }) => data[field] !== '');
    const hasTimeErrors = TIME_FIELDS.some(({ field }) => errors[field]);
    const [moreOpen, setMoreOpen] = useState(hasTimeValues);
    const showMore = moreOpen || hasTimeErrors;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Cierre</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField
                        id="responsible_name"
                        label="Responsable en planta"
                        error={errors.responsible_name}
                    >
                        <Input
                            placeholder="Nombre del operario"
                            maxLength={150}
                            value={data.responsible_name}
                            onChange={(event) =>
                                setData('responsible_name', event.target.value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="notes"
                        label="Observaciones"
                        error={errors.notes}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            maxLength={2000}
                            placeholder="Notas sobre el lote..."
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                </div>

                <Collapsible
                    open={showMore}
                    onOpenChange={(open) => setMoreOpen(open)}
                >
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="-ml-2"
                            disabled={hasTimeErrors}
                        >
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    showMore && 'rotate-180',
                                )}
                            />
                            Más datos (opcional)
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        {/* Fecha con hora: `PlantDateInput` solo maneja días, así que estos cuatro campos siguen con el
                            selector nativo `datetime-local`. Única excepción a DESIGN.md §8 (C1.4). */}
                        <div className="grid grid-cols-1 gap-4 pt-3 sm:grid-cols-2">
                            {TIME_FIELDS.map(({ field, label }) => (
                                <FormField
                                    key={field}
                                    id={field}
                                    label={label}
                                    error={errors[field]}
                                >
                                    <Input
                                        type="datetime-local"
                                        value={toPlantDateTimeInput(
                                            data[field],
                                        )}
                                        onChange={(event) =>
                                            setData(field, event.target.value)
                                        }
                                        disabled={isReadOnly}
                                    />
                                </FormField>
                            ))}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </CardContent>
        </Card>
    );
}
