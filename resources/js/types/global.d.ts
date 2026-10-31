import type { Auth, Tenancy } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            tenancy: Tenancy;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
