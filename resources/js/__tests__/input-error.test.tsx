import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import InputError from '@/components/input-error';

describe('InputError', () => {
    it('renders nothing when there is no message', () => {
        const { container } = render(<InputError />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders the validation message', () => {
        render(<InputError message="The email field is required." />);

        expect(
            screen.getByText('The email field is required.'),
        ).toBeInTheDocument();
    });
});
