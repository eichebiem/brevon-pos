export type BatchStatus = 'draft' | 'posted' | 'cancelled';

export type BatchRow = {
    id: number;
    number: string;
    status: BatchStatus;
    status_label: string;
    product: { id: number; sku: string; name: string } | null;
    planned: string;
    actual: string;
    rejected: string;
    created_by: string | null;
    created_at: string;
    produced_at: string;
};

export const batchTone: Record<BatchStatus, 'success' | 'neutral' | 'danger'> =
    {
        draft: 'neutral',
        posted: 'success',
        cancelled: 'danger',
    };
