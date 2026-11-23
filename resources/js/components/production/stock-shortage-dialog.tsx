import { DetailLabel } from '@/components/detail-list';
import { FormattedNumber } from '@/components/formatted-number';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Spinner } from '@/components/ui/spinner';
import type { ProductShortage, StockShortage } from '@/types/production-orders';

type StockShortageDialogProps = {
    open: boolean;
    shortages: StockShortage[];
    /** Lo que falta del producto terminado de la fórmula, de sus saldos y lotes libres. */
    productShortages?: ProductShortage[];
    /** Mientras se crea la orden, el diálogo sigue abierto y no se puede cerrar. */
    processing: boolean;
    onCancel: () => void;
    onConfirm: () => void;
};

function ShortageFigure({
    label,
    value,
    unit,
    className,
}: {
    label: string;
    value: string;
    unit: string;
    className?: string;
}) {
    return (
        <div className="min-w-0">
            <DetailLabel>{label}</DetailLabel>
            <dd className={className}>
                <FormattedNumber
                    value={value}
                    kind="quantity"
                    className="tabular-nums"
                />{' '}
                {unit}
            </dd>
        </div>
    );
}

/** Requerido, disponible y lo que falta, en tres columnas: igual para una materia prima y un producto. */
function ShortageFigures({
    required,
    available,
    missing,
    unit,
}: {
    required: string;
    available: string;
    missing: string;
    unit: string;
}) {
    return (
        <dl className="grid grid-cols-3 gap-2">
            <ShortageFigure label="Requerido" value={required} unit={unit} />
            <ShortageFigure label="Disponible" value={available} unit={unit} />
            <ShortageFigure
                label="Falta"
                value={missing}
                unit={unit}
                className="font-medium text-warning-text"
            />
        </dl>
    );
}

/** Un grupo del diálogo: su título (solo si hay dos grupos) y su lista, con el divisor solo entre sus elementos. */
function ShortageGroup({
    title,
    children,
}: {
    title: string | null;
    children: React.ReactNode;
}) {
    return (
        <section>
            {title && <h3 className="text-sm font-medium">{title}</h3>}
            <ul className="divide-y divide-border">{children}</ul>
        </section>
    );
}

/**
 * Avisa, antes de crear una orden, de todas las materias primas y los productos terminados de la fórmula que no alcanzan
 * en la bodega. No impide crearla: la orden puede usar saldo y gastar menos de lo que pide la fórmula, y lo que no deja
 * completarla es el consumo real (y, en un producto, que no tenga de dónde salir).
 */
export function StockShortageDialog({
    open,
    shortages,
    productShortages = [],
    processing,
    onCancel,
    onConfirm,
}: StockShortageDialogProps) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !processing) {
                    onCancel();
                }
            }}
        >
            {/* La lista puede ser larga: se desplaza ella y el diálogo nunca pasa del alto de la pantalla. */}
            <AlertDialogContent className="flex max-h-dvh flex-col">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Falta inventario para esta orden
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Puedes crearla, pero no se podrá completar hasta que
                        haya stock de lo que se vaya a consumir.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto text-sm">
                    {shortages.length > 0 && (
                        <ShortageGroup
                            title={
                                productShortages.length > 0
                                    ? 'Materia prima'
                                    : null
                            }
                        >
                            {shortages.map((shortage) => (
                                <li
                                    key={shortage.code}
                                    className="space-y-2 py-3"
                                >
                                    <p className="font-mono font-medium break-words">
                                        {shortage.code}
                                    </p>
                                    <ShortageFigures
                                        required={shortage.required}
                                        available={shortage.available}
                                        missing={shortage.missing}
                                        unit={shortage.unit}
                                    />
                                    {!shortage.has_reference_price && (
                                        <p className="text-xs text-muted-foreground">
                                            Sin precio de referencia: lo que
                                            falta no suma al costo estimado.
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ShortageGroup>
                    )}
                    {productShortages.length > 0 && (
                        <ShortageGroup title="Producto terminado">
                            {productShortages.map((shortage) => (
                                <li
                                    key={`pt-${shortage.product_id}`}
                                    className="space-y-2 py-3"
                                >
                                    <p className="font-medium break-words">
                                        {shortage.name}
                                    </p>
                                    <ShortageFigures
                                        required={shortage.required}
                                        available={shortage.available}
                                        missing={shortage.missing}
                                        unit={shortage.unit}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Libre en la bodega: saldos{' '}
                                        <FormattedNumber
                                            value={shortage.remnants_kg}
                                            kind="quantity"
                                            className="tabular-nums"
                                        />{' '}
                                        kg · lotes{' '}
                                        <FormattedNumber
                                            value={shortage.lots_kg}
                                            kind="quantity"
                                            className="tabular-nums"
                                        />{' '}
                                        kg
                                        {shortage.has_unknown_kg &&
                                            '. Un lote no tiene peso por galón: sus kilos no suman.'}
                                    </p>
                                </li>
                            ))}
                        </ShortageGroup>
                    )}
                </div>

                <AlertDialogFooter className="sm:justify-between">
                    <AlertDialogCancel disabled={processing}>
                        Cancelar
                    </AlertDialogCancel>
                    <AlertDialogAction
                        disabled={processing}
                        onClick={(event) => {
                            // Sin esto Radix cierra el diálogo al hacer clic, antes de que responda el servidor.
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {processing && <Spinner />}
                        Crear de todas formas
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
