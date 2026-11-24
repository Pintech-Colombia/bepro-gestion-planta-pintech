import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/** Una carga que falló, con la opción de reintentar (sin `onRetry`, solo el aviso: reintentar no lo arreglaría). */
export function RetryableError({
    message,
    onRetry,
}: {
    message: string;
    onRetry?: () => void;
}) {
    return (
        // El botón va fuera del aviso: dentro, el lector de pantalla lo leería con el mensaje.
        <div className="space-y-4">
            <Alert variant="destructive" role="alert">
                <AlertDescription>{message}</AlertDescription>
            </Alert>
            {onRetry && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onRetry}
                >
                    Reintentar
                </Button>
            )}
        </div>
    );
}
