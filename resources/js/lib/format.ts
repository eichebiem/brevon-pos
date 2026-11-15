/** Business display timezone (BR-020, D-031). Stored timestamps are UTC. */
export const BUSINESS_TIMEZONE = 'Asia/Manila';

const dateTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: BUSINESS_TIMEZONE,
    dateStyle: 'medium',
    timeStyle: 'short',
});

export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

const date = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'UTC',
    dateStyle: 'medium',
});

/** A calendar date stored as YYYY-MM-DD (already a Manila business date). */
export function formatDate(value: string | null | undefined): string {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

/**
 * Display a decimal string from the server (e.g. "45.50"). Display only:
 * amounts are never calculated in the browser (D-034).
 */
export function formatMoney(value: string | null | undefined): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : peso.format(Number(value));
}

/** Native <select> styling shared by admin forms and filters. */
export const selectClass =
    'h-9 rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50';

/** Display a DECIMAL(14,3) quantity without trailing zeros, e.g. "12.000" → "12". */
export function formatQuantity(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const [whole, fraction = ''] = value.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    const grouped = Number(whole).toLocaleString('en-PH');

    return trimmed ? `${grouped}.${trimmed}` : grouped;
}
