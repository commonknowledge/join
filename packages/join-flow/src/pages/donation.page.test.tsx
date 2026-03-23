import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { DonationPage } from './donation.page';

// Mock the env module
jest.mock('../env', () => ({
  get: jest.fn(),
}));

// Mock the summary component to keep tests simple
jest.mock('../components/summary', () => ({
  Summary: () => null,
}));

import { get as getEnv } from '../env';
const mockGetEnv = getEnv as jest.Mock;

const mockOnCompleted = jest.fn();
const baseData = {
  membership: 'standard',
} as any;

const MOCK_PLANS = [
  { value: 'plan-a', label: 'Plan A', amount: '5',  currency: 'GBP', frequency: 'monthly' },
  { value: 'plan-b', label: 'Plan B', amount: '10', currency: 'GBP', frequency: 'monthly' },
  { value: 'plan-c', label: 'Plan C', amount: '20', currency: 'GBP', frequency: 'monthly' },
];

function renderDonationPage(data = baseData) {
  return render(
    <DonationPage data={data} onCompleted={mockOnCompleted} />
  );
}

beforeEach(() => {
  mockGetEnv.mockReturnValue(false);
  mockOnCompleted.mockClear();
});

describe('DonationPage — standard mode (DONATION_SUPPORTER_MODE off)', () => {
  test('renders recurring checkbox when supporter mode is off', () => {
    mockGetEnv.mockReturnValue(false);
    renderDonationPage();

    expect(
      screen.getByLabelText(/Make this extra donation recurring/i)
    ).toBeInTheDocument();
  });

  test('does not render Monthly/One-off toggle when supporter mode is off', () => {
    mockGetEnv.mockReturnValue(false);
    renderDonationPage();

    expect(screen.queryByText('Monthly')).not.toBeInTheDocument();
    expect(screen.queryByText('One-off')).not.toBeInTheDocument();
  });
});

describe('DonationPage — supporter mode (DONATION_SUPPORTER_MODE on)', () => {
  beforeEach(() => {
    mockGetEnv.mockImplementation((key: string) => {
      if (key === 'DONATION_SUPPORTER_MODE') return true;
      if (key === 'MEMBERSHIP_PLANS') return MOCK_PLANS;
      if (key === 'USE_STRIPE') return true;
      return false;
    });
  });

  test('shows error when no membership plans are configured', () => {
    mockGetEnv.mockImplementation((key: string) => {
      if (key === 'DONATION_SUPPORTER_MODE') return true;
      if (key === 'MEMBERSHIP_PLANS') return [];
      return false;
    });
    renderDonationPage();

    expect(screen.getByText(/No donation amounts configured/i)).toBeInTheDocument();
    expect(screen.queryByText('Monthly')).not.toBeInTheDocument();
  });

  test('renders Monthly and One-off toggle buttons', () => {
    renderDonationPage();

    expect(screen.getByText('Monthly')).toBeInTheDocument();
    expect(screen.getByText('One-off')).toBeInTheDocument();
  });

  test('does not render recurring checkbox', () => {
    renderDonationPage();

    expect(
      screen.queryByLabelText(/Make this extra donation recurring/i)
    ).not.toBeInTheDocument();
  });

  test('Monthly is active by default and shows plan tiers', () => {
    renderDonationPage();

    expect(screen.getByText('Monthly')).toHaveClass('btn-dark');

    // Tiers from MOCK_PLANS amounts
    expect(screen.getByText('£5')).toBeInTheDocument();
    expect(screen.getByText('£10')).toBeInTheDocument();
    expect(screen.getByText('£20')).toBeInTheDocument();
  });

  test('same tiers are shown after switching to One-off', () => {
    renderDonationPage();

    fireEvent.click(screen.getByText('One-off'));

    // Same tiers — amounts do not change with the toggle
    expect(screen.getByText('£5')).toBeInTheDocument();
    expect(screen.getByText('£10')).toBeInTheDocument();
    expect(screen.getByText('£20')).toBeInTheDocument();
  });

  test('skip button is not present in supporter mode', () => {
    renderDonationPage();

    expect(screen.queryByText('skip for now')).not.toBeInTheDocument();
  });

  test('CTA label reflects monthly selection', () => {
    renderDonationPage();

    // Default: first plan tier £5, monthly
    expect(screen.getByText(/Donate £5\/month/i)).toBeInTheDocument();
  });

  test('CTA label reflects one-off selection', () => {
    renderDonationPage();

    fireEvent.click(screen.getByText('One-off'));

    // Same selected tier (£5), now one-off
    expect(screen.getByText(/Donate £5 now/i)).toBeInTheDocument();
  });

  test('submitting monthly calls onCompleted with recurDonation as native boolean true', async () => {
    renderDonationPage();

    fireEvent.click(screen.getByText(/Donate £5\/month/i));

    await waitFor(() => expect(mockOnCompleted).toHaveBeenCalled());
    const submitted = mockOnCompleted.mock.calls[0][0];
    expect(typeof submitted.recurDonation).toBe('boolean');
    expect(submitted.recurDonation).toBe(true);
  });

  test('submitting one-off calls onCompleted with recurDonation as native boolean false', async () => {
    renderDonationPage();

    fireEvent.click(screen.getByText('One-off'));
    fireEvent.click(screen.getByText(/Donate £5 now/i));

    await waitFor(() => expect(mockOnCompleted).toHaveBeenCalled());
    const submitted = mockOnCompleted.mock.calls[0][0];
    expect(typeof submitted.recurDonation).toBe('boolean');
    expect(submitted.recurDonation).toBe(false);
  });

  test('submitting calls onCompleted with donationAmount as a number', async () => {
    renderDonationPage();

    fireEvent.click(screen.getByText(/Donate £5\/month/i));

    await waitFor(() => expect(mockOnCompleted).toHaveBeenCalled());
    const submitted = mockOnCompleted.mock.calls[0][0];
    expect(typeof submitted.donationAmount).toBe('number');
    expect(submitted.donationAmount).toBe(5);
  });
});

describe('DonationPage — supporter mode, GoCardless only (USE_STRIPE off)', () => {
  beforeEach(() => {
    mockGetEnv.mockImplementation((key: string) => {
      if (key === 'DONATION_SUPPORTER_MODE') return true;
      if (key === 'MEMBERSHIP_PLANS') return MOCK_PLANS;
      if (key === 'USE_STRIPE') return false;
      return false;
    });
  });

  test('One-off button is disabled', () => {
    renderDonationPage();
    expect(screen.getByText('One-off').closest('button')).toBeDisabled();
  });

  test('shows explanation that one-off donations are not available', () => {
    renderDonationPage();
    expect(screen.getByText(/one-off donations are not available/i)).toBeInTheDocument();
  });

  test('Monthly button remains enabled', () => {
    renderDonationPage();
    expect(screen.getByText('Monthly').closest('button')).not.toBeDisabled();
  });
});
