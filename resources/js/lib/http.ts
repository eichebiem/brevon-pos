/** Validation errors as Laravel returns them (422). */
export class ValidationFailed extends Error {
    constructor(public readonly errors: Record<string, string[]>) {
        super('Validation failed');
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * POST JSON to a Laravel route outside Inertia (e.g. the cart quote), with
 * the CSRF token Laravel expects. Throws ValidationFailed on 422.
 */
export async function postJson<T>(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (response.status === 422) {
        const data = (await response.json()) as {
            errors?: Record<string, string[]>;
        };
        throw new ValidationFailed(data.errors ?? {});
    }

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return (await response.json()) as T;
}
