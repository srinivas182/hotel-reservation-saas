export type User = {
    id: string;
    first_name: string;
    last_name: string;
    name: string;
    phone_e164: string | null;
    preferred_locale: string;
    platform_role: 'super_admin' | 'support' | null;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type HotelSummary = {
    id: string;
    name: string;
    slug: string;
};

export type Tenancy = {
    current: HotelSummary | null;
    hotels: HotelSummary[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
