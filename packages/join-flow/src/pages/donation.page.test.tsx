import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
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
    mockGetEnv.mockImplementation((key: string) =>
      key === 'DONATION_SUPPORTER_MODE' ? true : false
    );
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

  test('Monthly is active by default and shows monthly tiers', () => {
    renderDonationPage();

    const monthlyBtn = screen.getByText('Monthly');
    expect(monthlyBtn).toHaveClass('btn-dark');

    // Monthly tiers: 3, 5, 10, 20
    expect(screen.getByText('£3')).toBeInTheDocument();
    expect(screen.getByText('£5')).toBeInTheDocument();
    expect(screen.getByText('£10')).toBeInTheDocument();
    expect(screen.getByText('£20')).toBeInTheDocument();
  });

  test('clicking One-off switches to one-off tiers', () => {
    renderDonationPage();

    fireEvent.click(screen.getByText('One-off'));

    // One-off tiers: 10, 25, 50, 100
    expect(screen.getByText('£25')).toBeInTheDocument();
    expect(screen.getByText('£50')).toBeInTheDocument();
    expect(screen.getByText('£100')).toBeInTheDocument();

    // Monthly-only tier £3 should no longer be present
    expect(screen.queryByText('£3')).not.toBeInTheDocument();
  });

  test('skip button is not present in supporter mode', () => {
    renderDonationPage();

    expect(screen.queryByText('skip for now')).not.toBeInTheDocument();
  });

  test('CTA label reflects monthly selection', () => {
    renderDonationPage();

    // Default: Monthly £5 (index 1 of [3,5,10,20])
    expect(screen.getByText(/Donate £5\/month/i)).toBeInTheDocument();
  });

  test('CTA label reflects one-off selection', () => {
    renderDonationPage();

    fireEvent.click(screen.getByText('One-off'));

    // Default after switching: £25 (index 1 of [10,25,50,100])
    expect(screen.getByText(/Donate £25 now/i)).toBeInTheDocument();
  });
});
