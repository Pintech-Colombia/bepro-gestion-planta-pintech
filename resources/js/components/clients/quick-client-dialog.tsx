import { useHttp } from '@inertiajs/react';
import { useState } from 'react';

import { ClientFields } from '@/components/clients/client-fields';
import type { ClientFieldsData } from '@/components/clients/client-fields';
import { FormActions } from '@/components/form-actions';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { focusFirstError } from '@/lib/focus-first-error';
import { quickStore as clientsQuickStore } from '@/routes/clients';

/** El cliente tal como lo responde `clients.quick-store`. */
export type CreatedClient = {
    id: number;
    business_name: string;
    nit: string | null;
    contact_name: string | null;
    phone: string | null;
    shipping_address: string | null;
};

const EMPTY_CLIENT: ClientFieldsData = {
    business_name: '',
    nit: '',
    contact_name: '',
    phone: '',
    shipping_address: '',
};

type QuickClientDialogProps = {
    /** Recibe el cliente creado: el formulario lo agrega a su lista y lo selecciona. */
    onCreated: (client: CreatedClient) => void;
};

/**
 * «Nuevo cliente» sin salir de un formulario (DESIGN.md §8: formulario corto en un `Dialog`). Envía con `useHttp` a
 * `clients.quick-store`, que aplica las mismas reglas que la página de clientes (NIT repetido incluido). Un error deja
 * el diálogo abierto con lo escrito.
 */
export function QuickClientDialog({ onCreated }: QuickClientDialogProps) {
    const [open, setOpen] = useState(false);
    // Un fallo que no es de validación (sin conexión, sesión vencida, error del servidor).
    const [failure, setFailure] = useState<string | null>(null);

    const http = useHttp<ClientFieldsData, CreatedClient>(EMPTY_CLIENT);

    const handleOpenChange = (next: boolean) => {
        if (http.processing) {
            return;
        }

        if (next) {
            http.reset();
            http.clearErrors();
            setFailure(null);
        }

        setOpen(next);
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFailure(null);

        http.post(clientsQuickStore().url, {
            onSuccess: (client) => {
                onCreated(client);
                setOpen(false);
                http.reset();
            },
            // El enfoque automático tras un 422 (`app.tsx`) solo escucha las visitas de Inertia, no `useHttp`: se pide
            // aquí, cuando React ya pintó los errores.
            onError: (errors) => {
                window.setTimeout(() => focusFirstError(errors), 50);
            },
            onHttpException: (response) => {
                setFailure(
                    response.status === 419
                        ? 'La sesión venció. Recarga la página e intenta de nuevo; lo escrito en la cotización se pierde si no la guardas antes.'
                        : 'No se pudo crear el cliente. Intenta de nuevo.',
                );

                return false;
            },
            onNetworkError: () => {
                setFailure(
                    'No hay conexión. Revisa la señal e intenta de nuevo: lo escrito sigue aquí.',
                );

                return false;
            },
        }).catch(() => {
            // El error ya se mostró en el diálogo (validación, servidor o red).
        });
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => handleOpenChange(true)}
            >
                Nuevo cliente
            </Button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent className="sm:max-w-2xl">
                    <form
                        noValidate
                        onSubmit={submit}
                        className="flex min-h-0 flex-col gap-4"
                    >
                        <DialogHeader>
                            <DialogTitle>Nuevo cliente</DialogTitle>
                            <DialogDescription>
                                Queda guardado y seleccionado en la cotización.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            {failure && (
                                <Alert variant="destructive" role="alert">
                                    <AlertDescription>
                                        {failure}
                                    </AlertDescription>
                                </Alert>
                            )}
                            <ClientFields
                                data={http.data}
                                setData={(field, value) => {
                                    http.setData(field, value);
                                    http.clearErrors(field);
                                }}
                                errors={http.errors}
                            />
                        </DialogBody>

                        <DialogFooter>
                            <FormActions
                                submitLabel="Guardar cliente"
                                processing={http.processing}
                                onCancel={() => handleOpenChange(false)}
                            />
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
