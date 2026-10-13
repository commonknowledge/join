import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { PlanPage } from './plan.page';

jest.mock('../env', () => ({
  get: jest.fn(),
  getStr: jest.fn(),
}));

jest.mock('../components/summary', () => ({
  Summary: () => null,
}));

import { get as getEnv, getStr as getEnvStr } from '../env';
const mockGetEnv = getEnv as jest.Mock;
const mockGetEnvStr = getEnvStr as jest.Mock;

const plan = (value: string, label: string, amount: string, bands: Record<string, string> = {}) => ({
  value, label, description: '', amount, currency: 'GBP', frequency: 'monthly', allowCustomAmount: false,
  incomeWeekly: '', incomeMonthly: '', ...bands,
});

const BANDED_PLANS = [
  plan('higher', 'Higher', '25', { incomeWeekly: 'More than £800', incomeMonthly: 'More than £3,200' }),
  plan('middle', 'Middle', '12', { incomeWeekly: '£400 to £800', incomeMonthly: '£1,600 to £3,200' }),
  plan('lower', 'Lower', '5', { incomeWeekly: 'Less than £400', incomeMonthly: 'Less than £1,600' }),
  plan('other', 'Other', '1'),
];

function withEnv(env: Record<string, unknown>) {
  const values: Record<string, unknown> = {
    MEMBERSHIP_PLANS: BANDED_PLANS,
    INCOME_GUIDANCE_ENABLED: true,
    INCOME_GUIDANCE_HEADING: 'Suggested dues by income',
    INCOME_GUIDANCE_NOTE: '',
    ...env,
  };
  mockGetEnv.mockImplementation((key: string) => values[key] ?? false);
  mockGetEnvStr.mockImplementation((key: string) => (values[key] as string) ?? '');
}

const onCompleted = jest.fn();

function renderPlanPage(membership = 'higher') {
  return render(<PlanPage data={{ membership } as any} setData={jest.fn()} onCompleted={onCompleted} />);
}

const guidanceTable = () => screen.queryByRole('table', { name: 'Suggested dues by income' });

beforeEach(() => {
  onCompleted.mockClear();
  withEnv({});
});

describe('Income guidance table — when it shows', () => {
  test('is not shown when income guidance is off, even if plans have bands', () => {
    withEnv({ INCOME_GUIDANCE_ENABLED: false });
    renderPlanPage();

    expect(guidanceTable()).not.toBeInTheDocument();
  });

  test('is not shown when no plan has an income band', () => {
    withEnv({ MEMBERSHIP_PLANS: [plan('higher', 'Higher', '25'), plan('lower', 'Lower', '5')] });
    renderPlanPage();

    expect(guidanceTable()).not.toBeInTheDocument();
  });

  test('shows the heading and one row per plan with a band', () => {
    renderPlanPage();

    const table = guidanceTable()!;
    expect(table).toBeInTheDocument();
    expect(screen.getByText('Suggested dues by income')).toBeInTheDocument();

    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows.map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent))).toEqual([
      ['More than £800', 'More than £3,200', '£25 monthly'],
      ['£400 to £800', '£1,600 to £3,200', '£12 monthly'],
      ['Less than £400', 'Less than £1,600', '£5 monthly'],
    ]);
  });

  test('labels the columns', () => {
    renderPlanPage();

    const headers = within(guidanceTable()!).getAllByRole('columnheader').map((th) => th.textContent);
    expect(headers).toEqual(['Weekly income', 'Monthly income', 'Suggested dues']);
  });

  test('leaves out a column that no plan fills in', () => {
    withEnv({
      MEMBERSHIP_PLANS: [
        plan('higher', 'Higher', '25', { incomeWeekly: 'More than £800' }),
        plan('lower', 'Lower', '5', { incomeWeekly: 'Less than £800' }),
      ],
    });
    renderPlanPage();

    const headers = within(guidanceTable()!).getAllByRole('columnheader').map((th) => th.textContent);
    expect(headers).toEqual(['Weekly income', 'Suggested dues']);
  });

  test('shows the note below the table when one is set', () => {
    withEnv({ INCOME_GUIDANCE_NOTE: "<p>Can't afford dues? <a href=\"mailto:dues@example.org\">Email us</a>.</p>" });
    renderPlanPage();

    expect(screen.getByRole('link', { name: 'Email us' })).toHaveAttribute('href', 'mailto:dues@example.org');
  });
});

describe('Income guidance table — choosing a plan', () => {
  const rowButton = (fee: string) => within(guidanceTable()!).getByRole('button', { name: new RegExp(fee) });

  test('the row for the selected plan is marked as chosen', () => {
    renderPlanPage('middle');

    expect(rowButton('£12 monthly')).toHaveAttribute('aria-pressed', 'true');
    expect(rowButton('£25 monthly')).toHaveAttribute('aria-pressed', 'false');
  });

  test('choosing a row selects that plan', async () => {
    renderPlanPage('higher');

    fireEvent.click(rowButton('£5 monthly'));

    await waitFor(() => expect(document.getElementById('membership-Lower')).toBeChecked());
    expect(rowButton('£5 monthly')).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: 'Continue and pay £5 monthly' })).toBeInTheDocument();
  });

  test('the chosen plan is the one submitted', async () => {
    renderPlanPage('higher');

    fireEvent.click(rowButton('£12 monthly'));
    fireEvent.submit(screen.getByRole('button', { name: /continue/i }).closest('form')!);

    await waitFor(() => expect(onCompleted).toHaveBeenCalled());
    expect(onCompleted.mock.calls[0][0].membership).toBe('middle');
  });

  test('choosing a tier directly moves the mark to its row', async () => {
    renderPlanPage('higher');

    fireEvent.click(document.getElementById('membership-Middle')!);

    await waitFor(() => expect(rowButton('£12 monthly')).toHaveAttribute('aria-pressed', 'true'));
    expect(rowButton('£25 monthly')).toHaveAttribute('aria-pressed', 'false');
  });
});
