import type { Auth } from '@/types/auth';
import type { SharedEnums } from '@/types/enums';
import type { StockShortage } from '@/types/production-orders';
import type {
    NewAlert,
    RecentAlert,
    UserNotifications,
    WarehouseContext,
} from '@/types/shared';

/** Props que HandleInertiaRequests::share() envía a todas las páginas. */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            success?: string;
            error?: string;
            /** Lo que falta para crear una orden. No es una notificación: lo muestra el formulario, en su diálogo. */
            stockShortages?: StockShortage[];
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** Hoy en la planta (`Y-m-d`): valor por defecto y límite de los campos de fecha. Lo valida PlantDate. */
            plantToday: string;
            /** `null` sin sesión. */
            warehouseContext: WarehouseContext | null;
            unresolvedAlertsCount: number;
            recentAlerts: RecentAlert[];
            /** Avisos personales de la campana; `null` sin sesión. No dependen de permisos. */
            notifications: UserNotifications | null;
            /** Pedidos «Por aprobar» visibles, para el contador de «Pedidos»; 0 sin `sales_orders.approve`. */
            pendingApprovalCount: number;
            /** Etiqueta y tono de los enums con color (`ToneEnums`). Llega una vez por sesión; sin sesión no existe. */
            enums?: SharedEnums;
            newAlerts: NewAlert[];
            [key: string]: unknown;
        };
    }
}
