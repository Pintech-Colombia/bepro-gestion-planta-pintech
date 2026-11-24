import { ClientFields } from '@/components/clients/client-fields';
import { FormActions } from '@/components/form-actions';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as clientsIndex } from '@/routes/clients';

interface ClientFormData {
    business_name: string;
    nit: string;
    contact_name: string;
    phone: string;
    shipping_address: string;
}

interface ClientFormProps {
    data: ClientFormData;
    setData: (field: string, value: string) => void;
    errors: Record<string, string>;
    processing: boolean;
    onSubmit: (e: React.FormEvent<HTMLFormElement>) => void;
    submitLabel: string;
    /** Campos adicionales al final de la tarjeta (p. ej., "Cliente activo" al editar). */
    children?: React.ReactNode;
}

export default function ClientForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    children,
}: ClientFormProps) {
    return (
        <form noValidate onSubmit={onSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Información del cliente</CardTitle>
                    <CardDescription>
                        Ingresa los datos del cliente.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <ClientFields
                        data={data}
                        setData={setData}
                        errors={errors}
                    />

                    {children}
                </CardContent>
            </Card>

            <FormActions
                submitLabel={submitLabel}
                processing={processing}
                cancelHref={clientsIndex().url}
            />
        </form>
    );
}
