import { FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { SHIPPING_ADDRESS_MAX_LENGTH } from '@/lib/shipping-address';

export type ClientFieldsData = {
    business_name: string;
    nit: string;
    contact_name: string;
    phone: string;
    shipping_address: string;
};

type ClientFieldsProps = {
    data: ClientFieldsData;
    setData: (field: keyof ClientFieldsData, value: string) => void;
    errors: Partial<Record<keyof ClientFieldsData, string>>;
};

/**
 * Los cinco campos de un cliente (las reglas de `StoreClientRequest`). Una sola fuente para la página de clientes y el
 * diálogo «Nuevo cliente» de la cotización.
 */
export function ClientFields({ data, setData, errors }: ClientFieldsProps) {
    return (
        <div className="grid gap-5 md:grid-cols-2">
            <FormField
                id="business_name"
                label="Razón social"
                required
                error={errors.business_name}
            >
                <Input
                    value={data.business_name}
                    onChange={(e) => setData('business_name', e.target.value)}
                    placeholder="Razón social o nombre completo"
                />
            </FormField>

            <FormField id="nit" label="NIT" error={errors.nit}>
                <Input
                    value={data.nit}
                    onChange={(e) => setData('nit', e.target.value)}
                    placeholder="NIT (opcional)"
                />
            </FormField>

            <FormField
                id="contact_name"
                label="Nombre de contacto"
                error={errors.contact_name}
            >
                <Input
                    value={data.contact_name}
                    onChange={(e) => setData('contact_name', e.target.value)}
                    placeholder="Nombre de la persona de contacto"
                />
            </FormField>

            <FormField id="phone" label="Teléfono" error={errors.phone}>
                <Input
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                    placeholder="Teléfono de contacto"
                />
            </FormField>

            <FormField
                id="shipping_address"
                label="Dirección"
                error={errors.shipping_address}
                className="md:col-span-2"
            >
                <Textarea
                    maxLength={SHIPPING_ADDRESS_MAX_LENGTH}
                    value={data.shipping_address}
                    onChange={(e) =>
                        setData('shipping_address', e.target.value)
                    }
                    placeholder="Calle, número, barrio, ciudad"
                />
            </FormField>
        </div>
    );
}
