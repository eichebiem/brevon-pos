/** Shapes sent by PosController / OrderReceipt (Phase 4c). Money is a decimal string from the server. */

export type PosOption = { id: number; name: string; price_delta: string };

export type PosOptionGroup = {
    id: number;
    name: string;
    single: boolean;
    min: number;
    max: number | null;
    options: PosOption[];
};

export type PosVariant = {
    id: number;
    name: string;
    price: string;
    on_hand: string | null;
};

export type PosProduct = {
    id: number;
    name: string;
    sku: string;
    image_url: string | null;
    tab_id: number;
    group: string | null;
    price: string;
    prices_vary: boolean;
    on_hand: string | null;
    variants: PosVariant[];
    option_groups: PosOptionGroup[];
};

export type PosCatalog = {
    categories: { id: number; name: string }[];
    products: PosProduct[];
};

export type PosPaymentMethod = {
    code: string;
    name: string;
    type: 'cash' | 'e_wallet' | 'card' | 'other';
    requires_reference: boolean;
};

export type PosOrderType = {
    value: string;
    label: string;
    needs_pickup: boolean;
};

/** A cart line kept in the browser: ids and choices only, never prices (BR-003). */
export type CartLine = {
    key: string;
    product_id: number;
    variant_id: number | null;
    option_ids: number[];
    quantity: number;
    notes: string;
};

export type QuoteLine = {
    product_id: number;
    variant_id: number | null;
    name: string;
    variant: string | null;
    quantity: string;
    unit_price: string;
    option_total: string;
    unit_total: string;
    options: { id: number; name: string; price_delta: string }[];
    line_total: string;
};

export type Quote = {
    lines: QuoteLine[];
    subtotal: string;
    discount_total: string;
    tax_total: string;
    grand_total: string;
    change: string | null;
};

export type LastOrder = {
    id: number;
    order_number: string;
    status: string;
    payment_status: string;
    grand_total: string;
    payment_method: string | null;
    amount_tendered: string | null;
    change_amount: string;
    reference_number: string | null;
};

export type Receipt = {
    title: string;
    store: {
        name: string | null;
        logo_url: string | null;
        footer: string | null;
    };
    location: {
        code: string;
        name: string;
        address: string | null;
    };
    order: {
        id: number;
        order_number: string;
        order_type: string;
        order_type_label: string;
        status: string;
        payment_status: string;
        created_at: string;
        pickup_at: string | null;
        notes: string | null;
        cashier: string | null;
        terminal: string | null;
        subtotal: string;
        discount_total: string;
        tax_total: string;
        grand_total: string;
    };
    items: {
        name: string;
        variant: string | null;
        quantity: string;
        unit_price: string;
        option_total: string;
        line_total: string;
        notes: string | null;
        options: { name: string; price_delta: string }[];
    }[];
    payments: {
        method: string | null;
        amount: string;
        amount_tendered: string | null;
        change_amount: string;
        reference_number: string | null;
        status: string;
    }[];
};
