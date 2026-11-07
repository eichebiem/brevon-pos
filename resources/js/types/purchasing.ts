export type OrderStatus =
    | 'draft'
    | 'submitted'
    | 'partially_received'
    | 'received'
    | 'cancelled';

export type OrderRow = {
    id: number;
    number: string;
    status: OrderStatus;
    status_label: string;
    supplier: { id: number; supplier_code: string; name: string } | null;
    expected_at: string | null;
    subtotal: string;
    created_by: string | null;
    created_at: string;
    items_count: number;
};

export type PurchaseItem = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    precision: number;
};

export const orderTone: Record<
    OrderStatus,
    'success' | 'warning' | 'neutral' | 'danger' | 'info'
> = {
    draft: 'neutral',
    submitted: 'info',
    partially_received: 'warning',
    received: 'success',
    cancelled: 'danger',
};

/** Quantity × cost rounded to the centavo, for display only (the server recalculates). */
export function lineTotal(quantity: string, cost: string): number | null {
    const q = Number(quantity);
    const c = Number(cost);

    if (
        !quantity.trim() ||
        !cost.trim() ||
        Number.isNaN(q) ||
        Number.isNaN(c)
    ) {
        return null;
    }

    return Math.round(q * c * 100) / 100;
}
