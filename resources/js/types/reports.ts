export type ReportFilters = {
    from: string;
    to: string;
    location: string;
    cashier: number | null;
    category: number | null;
    payment_method: number | null;
    group: 'day' | 'week' | 'month';
};

export type Option = { id: number; name: string };
export type LocationOption = { id: number; label: string };

/** D-156: the same figures on every row. */
export type SalesFigures = {
    gross: string;
    discounts: string;
    refunds: string;
    net: string;
    tax: string;
    orders: number;
    average: string;
    quantity: string;
};

export type PeriodRow = SalesFigures & { period: string; start: string };
export type ProductRow = SalesFigures & {
    name: string;
    variant: string | null;
    category: string | null;
};
export type NamedRow = SalesFigures & { name: string };
export type CashierRow = NamedRow & { shifts: number };

export type CollectionRow = {
    method: string;
    payments: number;
    received: string;
    waiting: string;
    refunds: number;
    given_back: string;
    net: string;
};
