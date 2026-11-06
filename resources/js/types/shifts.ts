export type CashMovementRow = {
    id: number;
    type: string;
    type_label: string;
    amount: string;
    corrects: 'expected' | 'counted' | null;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    by: string | null;
    by_id: number;
    decided_by: string | null;
    decision_notes: string | null;
    at: string;
};

export type MovementType = { value: string; label: string };

export type ShiftRow = {
    id: number;
    number: string;
    status: 'open' | 'closed';
    cashier: string | null;
    terminal: string | null;
    opened_at: string;
    closed_at: string | null;
    opening_cash: string;
    expected_cash: string | null;
    counted_cash: string | null;
    variance: string | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
    needs_review?: boolean;
};

export type ShiftSummary = {
    opening_cash: string;
    cash_sales: string;
    cancelled_cash: string;
    cash_in: string;
    cash_out: string;
    paid_out: string;
    refunds: string;
    expected_cash: string;
    counted_cash: string | null;
    variance: string | null;
    expected_correction: string;
    counted_correction: string;
    corrected_expected: string;
    corrected_counted: string | null;
    corrected_variance: string | null;
    non_cash: {
        method: string;
        verified: string;
        pending: string;
        pending_count: number;
    }[];
    order_count: number;
    cancelled_count: number;
    pending_movements: number;
    needs_review: boolean;
};
