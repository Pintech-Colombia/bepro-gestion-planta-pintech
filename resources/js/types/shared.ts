/** Bodega en el selector de la cabecera (`warehouseContext`, HandleInertiaRequests). */
export type WarehouseOption = {
    id: number;
    name: string;
    city: string;
};

export type WarehouseContext = {
    current: WarehouseOption | null;
    available: WarehouseOption[];
};

/** Alerta nueva de la petición anterior (notificación emergente). */
export type NewAlert = {
    id: number;
    message: string;
    severity: string;
    type: string;
    type_label: string;
};

/** Aviso personal sin leer de la campana (notificación de Laravel, canal `database`). */
export type UserNotification = {
    id: string;
    message: string;
    sales_order_id: number | null;
    created_at: string | null;
};

/** Avisos personales del usuario: cuántos sin leer y los diez más recientes. */
export type UserNotifications = {
    unread_count: number;
    items: UserNotification[];
};

/** Alerta sin resolver de la campana de la cabecera. */
export type RecentAlert = {
    id: number;
    type: string;
    type_label: string;
    severity: string;
    severity_label: string;
    message: string;
    created_at: string | null;
    raw_material_code: string | null;
};
