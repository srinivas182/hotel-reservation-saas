import type { SVGAttributes } from 'react';

/** Neutral concierge-bell mark, used until a brand logo is uploaded (Platform → Website). */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path d="M8 46h48v5H8zM32 17a17 17 0 0 1 17 17v9H15v-9a17 17 0 0 1 17-17zm-2.5-7h5v6h-5z" />
        </svg>
    );
}
