import type { FormDataErrors } from '@inertiajs/core';
import type { SetDataAction } from '@inertiajs/react';

export type ProductionOrderStatus =
    'pending' | 'in_progress' | 'pending_review' | 'completed' | 'cancelled';

export type FormNumberValue = string | number;

/** Una materia prima que no alcanza para la orden. Las cantidades llegan como texto, en la unidad de la materia prima. */
export type StockShortage = {
    code: string;
    unit: string;
    required: string;
    available: string;
    missing: string;
    has_reference_price: boolean;
};

export type VariantOption = {
    id: number;
    presentation_label: string;
    presentation_value: number;
};

export type RawMaterialOption = {
    id: number;
    label: string;
};

/** Etiqueta elegible en el plan de envasado (materia prima de tipo Etiqueta). */
export type LabelMaterialOption = {
    id: number;
    code: string;
    is_active: boolean;
};

export type ProductionOrderRawMaterial = {
    code?: string | null;
    unit_symbol?: string | null;
};

export type ProductionOrderDetail = {
    id: number;
    step_order?: number | null;
    raw_material?: ProductionOrderRawMaterial | null;
    planned_quantity: FormNumberValue;
    display_quantity?: FormNumberValue | null;
    display_unit?: string | null;
    /** En la unidad en que la línea se digita (`display_unit`, o la de la materia prima si no tiene). */
    actual_display_quantity?: FormNumberValue | null;
    unit_cost?: FormNumberValue | null;
    total_cost?: FormNumberValue | null;
};

export type ProductionOrderProductVariant = {
    presentation_label: string;
    presentation_value?: number | null;
};

export type ProductionOrderPackagingPlan = {
    id: number;
    product_variant?: ProductionOrderProductVariant | null;
    planned_units: FormNumberValue;
    actual_units?: FormNumberValue | null;
    /** Envase de la presentación: el que se descuenta al completar. */
    package_code?: string | null;
    /** Vacío = tantos como unidades envasadas. */
    new_containers_used?: FormNumberValue | null;
    label_raw_material_id?: number | null;
    label_code?: string | null;
    /** Vacío = tantas como unidades envasadas. */
    labels_used?: FormNumberValue | null;
};

export type ProductionOrderLineAdjustment = {
    id: number;
    raw_material?: ProductionOrderRawMaterial | null;
    quantity: FormNumberValue;
    reason: string;
};

export type ProductionOrderRemnant = {
    id: number;
    original_quantity_gallons: FormNumberValue;
    available_quantity_gallons: FormNumberValue;
    original_quantity_kg: FormNumberValue;
    available_quantity_kg: FormNumberValue;
    density_kg_per_gallon: FormNumberValue;
    cost_per_gallon?: FormNumberValue | null;
    status: string;
    status_label: string;
};

/**
 * Un saldo de PT que usa la orden: una línea planeada hasta que la orden se completa, cuando se descuenta del saldo.
 * Mientras está planeada, los galones y el costo son un estimado.
 */
export type ProductionOrderRemnantConsumption = {
    id: number;
    remnant_id: number;
    source_order_number?: string;
    source_lot_number?: number;
    /** Producto con el color de la orden de origen: qué se mezcla en esta orden. */
    source_product_name: string;
    quantity_gallons: FormNumberValue;
    quantity_kg: FormNumberValue;
    /** Solo con costs.view: el real si ya se aplicó; el estimado si está planeada. */
    consumed_cost?: FormNumberValue | null;
    /**
     * applied: descontado del saldo al completar · planned: planeado en una orden abierta (galones y costo estimados) ·
     * unused: la orden ya no está abierta (cancelada) y la línea queda como historial, sin costo.
     */
    state: 'applied' | 'planned' | 'unused';
    notes?: string | null;
    consumed_at: string;
    consumed_by?: { id: number; name: string } | null;
    can: { remove: boolean };
};

export type ProductionOrderAvailableRemnant = {
    id: number;
    source_order_number: string;
    source_lot_number: number;
    /** Producto con el color de la orden de origen: un saldo puede venir de cualquier producto de la bodega. */
    product_name: string;
    /** Los kilos libres: lo disponible menos lo ya planeado en órdenes abiertas. */
    available_quantity_kg: string;
};

export type ProductionOrder = {
    id: number;
    order_number: string;
    lot_number: number;
    /** Color que pidió el cliente (3.4). */
    color?: string | null;
    /** Nombre del producto con el color: «Esmalte rojo RAL 3020». */
    product_display_name?: string;
    status: ProductionOrderStatus;
    quantity: FormNumberValue;
    actual_quantity?: FormNumberValue | null;
    viscosity_ku?: FormNumberValue | null;
    grinding_hg?: FormNumberValue | null;
    quality_solids?: FormNumberValue | null;
    agitation_start_time?: string | null;
    agitation_end_time?: string | null;
    packaging_start_time?: string | null;
    packaging_end_time?: string | null;
    responsible_name?: string | null;
    spillage_quantity?: FormNumberValue | null;
    remnant_quantity_gallons?: string | null;
    remnant_quantity_kg?: string | null;
    remnant_notes?: string | null;
    notes?: string | null;
    completion_date?: string | null;
    /** Solo con «Ver costos»; null si la orden está cancelada. */
    cost_summary?: ProductionOrderCostSummary | null;
    qr_landing_url?: string | null;
    qr_image_url?: string | null;
    submitted_at?: string | null;
    reviewed_at?: string | null;
    rejection_reason?: string | null;
    submitted_by?: { id: number; name: string } | null;
    reviewed_by?: { id: number; name: string } | null;
    quality_responsible_user_id?: number | null;
    quality_responsible_user?: {
        id: number;
        name: string;
        job_title: string;
    } | null;
    product?: {
        name?: string | null;
        quality_solids_lower?: FormNumberValue | null;
        quality_solids_upper?: FormNumberValue | null;
    } | null;
    formula?: {
        version?: FormNumberValue | null;
        name?: string | null;
    } | null;
    warehouse?: {
        name?: string | null;
    } | null;
    planned_date?: string | null;
    density_kg_per_gallon?: FormNumberValue | null;
    details?: ProductionOrderDetail[];
    packaging_plans?: ProductionOrderPackagingPlan[];
    line_adjustments?: ProductionOrderLineAdjustment[];
    remnant?: ProductionOrderRemnant | null;
    remnant_consumptions?: ProductionOrderRemnantConsumption[];
    available_remnants?: ProductionOrderAvailableRemnant[];
};

export type ProductionOrderCan = {
    startProduction: boolean;
    submitForReview: boolean;
    complete: boolean;
    rejectReview: boolean;
    previewCosts: boolean;
    /** Agregar y quitar saldos de PT: pendiente o en curso con `operate`; en revisión, solo quien puede revisarla. */
    planRemnant: boolean;
    updateColor: boolean;
    printLabels: boolean;
    /** Botón «Cancelar orden» (D-OPX2): permiso y estado pendiente o en curso. */
    cancel: boolean;
};

export type ProductionOrderShowProps = {
    order: ProductionOrder;
    rawMaterials: RawMaterialOption[];
    labelMaterials: LabelMaterialOption[];
    availableVariants: VariantOption[];
    qualitySigners: QualitySignerOption[];
    returnTo?: string | null;
    can: ProductionOrderCan;
};

export type ProductionOrderIngredientFormRow = {
    id: number;
    /** Paso de la fórmula: la misma materia prima puede ir en varios pasos (D-FOR2). */
    step_order?: number | null;
    raw_material_name?: string | null;
    raw_material_unit?: string | null;
    planned_quantity: FormNumberValue;
    display_quantity?: FormNumberValue | null;
    display_unit?: string | null;
    /** Lo digitado; el servidor lo convierte a la unidad de la materia prima. */
    actual_quantity: FormNumberValue;
    unit_cost: FormNumberValue;
    total_cost: FormNumberValue;
};

export type ProductionOrderPackagingFormRow = {
    id: number;
    presentation: string;
    presentation_value: number;
    planned_units: FormNumberValue;
    actual_units: FormNumberValue;
    package_code: string | null;
    /** '' = tantos como unidades envasadas. */
    new_containers_used: FormNumberValue;
    label_raw_material_id: number | null;
    /** La etiqueta guardada en el plan: una inactiva sigue ofreciéndose aunque se elija otra. */
    saved_label_raw_material_id: number | null;
    /** '' = tantas como unidades envasadas. */
    labels_used: FormNumberValue;
};

export type ProductionOrderFormData = {
    viscosity_ku: FormNumberValue;
    grinding_hg: FormNumberValue;
    quality_solids: FormNumberValue;
    agitation_start_time: string;
    agitation_end_time: string;
    packaging_start_time: string;
    packaging_end_time: string;
    responsible_name: string;
    quality_responsible_user_id: number | null;
    spillage_quantity: FormNumberValue;
    density_kg_per_gallon: FormNumberValue;
    remnant_quantity_kg: FormNumberValue;
    remnant_notes: string;
    notes: string;
    ingredients: ProductionOrderIngredientFormRow[];
    packaging: ProductionOrderPackagingFormRow[];
};

export type QualitySignerOption = {
    id: number;
    name: string;
    job_title: string;
    signature_url: string | null;
};

export type ProductionOrderSetData = SetDataAction<ProductionOrderFormData>;

export type ProductionOrderErrors = FormDataErrors<ProductionOrderFormData>;

/**
 * Resumen de costos de la OP (`ProductionOrderCostSummary` en el servidor): la tarjeta, la tabla de envasado, el PDF y el
 * Excel salen de él. Montos como texto decimal, sin CIF salvo los `*_with_cif`; la pantalla solo los pinta.
 */
export type ProductionOrderCostSummary = {
    /** estimated: pendiente o abierta (lo planeado o lo digitado) · actual: completada (lo guardado). */
    basis: 'estimated' | 'actual';
    /** Completada antes de que la OP guardara su CIF, sin lote ni saldo que lo fijara: CIF del producto al migrar. */
    cif_estimated: boolean;
    bulk: {
        total: string;
        raw_materials: string;
        line_adjustments: string;
        remnants_used: string;
    };
    yield_gallons: string;
    cost_per_gallon: string | null;
    cost_per_gallon_with_cif: string | null;
    cost_per_kg: string | null;
    packaged: {
        total: string;
        bulk: string;
        packaging_materials: string;
        total_with_cif: string;
    };
    remnant: {
        gallons: string;
        cost_with_cif: string | null;
        total_with_remnant: string | null;
    } | null;
    /** Por fila del plan de envasado con unidades. */
    packaging: Array<{
        id: number;
        units: string;
        unit_cost: string;
        unit_cost_with_cif: string;
        total_cost: string;
    }>;
    /** Contra la lista de precios de hoy. */
    comparison: {
        reference_cost_per_gallon: string | null;
        reference_difference_percentage: string | null;
        uses_reference_formula: boolean;
        /** null: el producto no tiene margen en la lista. */
        list_margin_percentage: string | null;
        presentations: Array<{
            id: number;
            presentation_label: string | null;
            unit_cost_with_cif: string;
            list_price: string | null;
            list_difference_percentage: string | null;
            real_margin_percentage: string | null;
        }>;
    };
};

export type ProductionOrderPackagingCost =
    ProductionOrderCostSummary['packaging'][number];

export type PreviewCostData = {
    ingredients: Array<{
        id: number;
        unit_cost: FormNumberValue;
        total_cost: FormNumberValue;
        actual_quantity: number;
    }>;
    summary: ProductionOrderCostSummary | null;
};
