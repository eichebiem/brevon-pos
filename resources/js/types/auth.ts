export type User = {
    id: number;
    name: string;
    username: string | null;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
};

export type Auth = {
    user: User;
    roles: string[];
    permissions: string[];
};

export type LocationSummary = {
    id: number;
    code: string;
    name: string;
    type: 'permanent' | 'pop_up';
};

export type LocationContext = {
    current: LocationSummary | null;
    available: LocationSummary[];
} | null;

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
