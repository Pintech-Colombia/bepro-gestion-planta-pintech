import { StatusBadge } from '@/components/status-badge';

/**
 * Etiqueta «Parcial» de un pedido aprobado en parte, junto a su estado (detalle, listado y tarjeta del celular). La
 * etiqueta y el tono salen del enum `SalesOrderApproval`, como cualquier pastilla de estado.
 */
export function PartialApprovalBadge() {
    return <StatusBadge enum="SalesOrderApproval" value="partial" />;
}
