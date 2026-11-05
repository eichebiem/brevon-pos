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
