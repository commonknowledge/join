import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { PlanPage } from './plan.page';

jest.mock('../env', () => ({
  get: jest.fn(),
  getStr: jest.fn(() => ''),
}));

jest.mock('../components/summary', () => ({
  Summary: () => null,
}));

import { get as getEnv } from '../env';
const mockGetEnv = getEnv as jest.Mock;

const mockOnCompleted = jest.fn();

const MOCK_PLANS = [
  { value: 'higher', label: 'Higher income', description: '', amount: '20', currency: 'GBP', frequency: 'monthly', allowCustomAmount: false },
  { value: 'lower', label: 'Lower income', description: '', amount: '5', currency: 'GBP', frequency: 'monthly', allowCustomAmount: false },
  { value: 'other', label: 'Other', description: '', amount: '1', currency: 'GBP', frequency: 'monthly', allowCustomAmount: true },
];

beforeEach(() => {
  mockGetEnv.mockImplementation((key: string) => {
    if (key === 'MEMBERSHIP_PLANS') return MOCK_PLANS;
    return false;
  });
  mockOnCompleted.mockClear();
});

describe('PlanPage — custom amount', () => {
  test('typing a custom amount selects that tier instead of the default', async () => {
    render(<PlanPage data={{ membership: 'higher' } as any} onCompleted={mockOnCompleted} />);

    const customInput = document.getElementById('other-amount') as HTMLInputElement;
    expect(customInput).toBeInTheDocument();

    fireEvent.change(customInput, { target: { value: '7' } });

    fireEvent.submit(screen.getByRole('button', { name: /continue/i }).closest('form')!);

    await waitFor(() => expect(mockOnCompleted).toHaveBeenCalled());
    const submitted = mockOnCompleted.mock.calls[0][0];
    expect(submitted.membership).toBe('other');
    expect(Number(submitted.customMembershipAmount)).toBe(7);
  });

  test('submitting without touching the custom amount keeps the default tier', async () => {
    render(<PlanPage data={{ membership: 'higher' } as any} onCompleted={mockOnCompleted} />);

    fireEvent.submit(screen.getByRole('button', { name: /continue/i }).closest('form')!);

    await waitFor(() => expect(mockOnCompleted).toHaveBeenCalled());
    expect(mockOnCompleted.mock.calls[0][0].membership).toBe('higher');
  });
});
