/** Shapes sent by PosController / OrderReceipt (Phase 4c). Money is a decimal string from the server. */

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
};

/** A cart line kept in the browser: ids and choices only, never prices (BR-003). */
export type CartLine = {
    key: string;
    product_id: number;
    variant_id: number | null;
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
    line_total: string;
    discount_amount: string;
};

export type Quote = {
    lines: QuoteLine[];
    subtotal: string;
    discount_total: string;
    discount: {
        id: number;
        name: string;
        lines: number[];
        amount: string;
    } | null;
    discount_needs_approval: boolean;
    grand_total: string;
    change: string | null;
};

/** A discount the counter can offer (D-145). */
export type PosDiscount = {
    id: number;
    name: string;
    code: string | null;
    type: 'percentage' | 'fixed';
    value: string;
    applies_to: string | null;
    senior_pwd: boolean;
    requires_approval: boolean;
};

export type Beneficiary = {
    type: 'senior_citizen' | 'pwd' | '';
    name: string;
    card_number: string;
    address: string;
};

/** The discount chosen for the sale on screen; line keys survive cart edits. */
export type ChosenDiscount = {
    id: number;
    lineKeys: string[] | null;
    beneficiary: Beneficiary | null;
    reason: string;
};

export type ReceiptBeneficiary = {
    type: string;
    type_label: string;
    name: string;
    card_number: string;
    address: string | null;
    masked: boolean;
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
        notes: string | null;
        cashier: string | null;
        terminal: string | null;
        subtotal: string;
        discount_total: string;
        grand_total: string;
    };
    items: {
        name: string;
        variant: string | null;
        quantity: string;
        unit_price: string;
        line_total: string;
        discount_amount: string;
        notes: string | null;
    }[];
    discount: {
        name: string;
        code: string | null;
        type: string;
        value: string;
        amount: string;
        approved_by: string | null;
        beneficiary: ReceiptBeneficiary | null;
    } | null;
    payments: {
        method: string | null;
        amount: string;
        amount_tendered: string | null;
        change_amount: string;
        reference_number: string | null;
        status: string;
    }[];
    refunds: { refund_number: string; amount: string; at: string }[];
};

export type RefundRecord = {
    refund_number: string;
    kind: 'refund' | 'void';
    kind_label: string;
    amount: string;
    method: string | null;
    reference_number: string | null;
    reason: string;
    by: string | null;
    approved_by: string | null;
    at: string;
    items: {
        name: string | null;
        variant: string | null;
        quantity: string;
        amount: string;
        return_to_stock: boolean;
    }[];
};

export type RefundForm = {
    method: string | null;
    is_cash: boolean;
    requires_reference: boolean;
    has_drawer: boolean;
    can_approve: boolean;
    approval_over: string;
    refunded: string;
    lines: {
        id: number;
        name: string;
        variant: string | null;
        sold: string;
        remaining_quantity: string;
        remaining_amount: string;
        can_return_to_stock: boolean;
    }[];
};
