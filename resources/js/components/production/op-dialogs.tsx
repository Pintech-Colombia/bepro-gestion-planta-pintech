import { useForm } from '@inertiajs/react';

import { rejectReview as productionOrderRejectReview } from '@/actions/App/Http/Controllers/ProductionOrderController';
import { FormError, FormField } from '@/components/form-field';
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
import { Textarea } from '@/components/ui/textarea';
import { formatPercent, formatQuantity } from '@/lib/formatters';

type OpSubmitDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    realYield: number | null;
    yieldPercentage: number | null;
    processing: boolean;
    processingLabel: string;
    onConfirm: () => void;
};

/** Confirmar enviar a revisión, aprobar o finalizar, con el rendimiento real a la vista. */
export function OpSubmitDialog({
    open,
    onOpenChange,
    title,
    description,
    realYield,
    yieldPercentage,
    processing,
    processingLabel,
    onConfirm,
}: OpSubmitDialogProps) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description} Rendimiento real:{' '}
                        {formatQuantity(realYield, {
                            maxDecimals: 2,
                            trimTrailingZeros: true,
                        })}{' '}
                        gal
                        {yieldPercentage !== null &&
                            ` (${formatPercent(yieldPercentage, 1)} de lo proyectado)`}
                        .
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        Cancelar
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing ? processingLabel : 'Confirmar'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

type OpStartDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderNumber: string;
    starting: boolean;
    onConfirm: () => void;
};

/** Confirmar el inicio de la producción de una orden pendiente. */
export function OpStartDialog({
    open,
    onOpenChange,
    orderNumber,
    starting,
    onConfirm,
}: OpStartDialogProps) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>¿Iniciar producción?</AlertDialogTitle>
                    <AlertDialogDescription>
                        La orden {orderNumber} pasará a estado En proceso.
                        Podrás registrar datos de planta y enviarla a revisión
                        cuando esté lista.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={starting}>
                        Cancelar
                    </AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm} disabled={starting}>
                        {starting ? 'Iniciando...' : 'Iniciar producción'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

type OpRejectDialogProps = {
    orderId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Devolver a planta una orden en revisión, con el motivo para que el operador la corrija. */
export function OpRejectDialog({
    orderId,
    open,
    onOpenChange,
}: OpRejectDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });
    // La orden cambió de estado mientras se escribía el motivo (RejectsStaleOrderWrites): el diálogo sigue abierto.
    const staleError = (errors as Partial<Record<string, string>>)
        .production_order;

    const confirmReject = () => {
        post(productionOrderRejectReview({ production_order: orderId }).url, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <form
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        confirmReject();
                    }}
                >
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            ¿Devolver orden a planta?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Indica el motivo del rechazo para que el operador
                            pueda corregirlo.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-1 py-4">
                        <FormField
                            id="reason"
                            label="Motivo del rechazo"
                            required
                            error={errors.reason}
                        >
                            <Textarea
                                maxLength={2000}
                                placeholder="Describe qué debe corregirse..."
                                value={data.reason}
                                onChange={(event) =>
                                    setData('reason', event.target.value)
                                }
                            />
                        </FormField>
                        <FormError id="production_order" message={staleError} />
                    </div>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processing}>
                            Cancelar
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={processing}
                            onClick={(event) => {
                                // Sin esto Radix cierra el diálogo al hacer clic, antes de que responda el
                                // servidor, y un error del motivo queda oculto hasta reabrirlo.
                                event.preventDefault();
                                confirmReject();
                            }}
                        >
                            {processing
                                ? 'Devolviendo...'
                                : 'Devolver a planta'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
