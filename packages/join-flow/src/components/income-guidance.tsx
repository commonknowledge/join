import React, { FC } from "react";
import { currencyCodeToSymbol } from "../schema";
import { get as getEnv, getStr as getEnvStr } from "../env";

export interface IncomeGuidancePlan {
  value: string;
  amount: string | number;
  currency: string;
  frequency: string;
  allowCustomAmount?: boolean;
  incomeWeekly?: string;
  incomeMonthly?: string;
}

interface IncomeGuidanceProps {
  groupedPlans: Record<string, IncomeGuidancePlan[]>;
  selected?: string;
  onSelect: (plan: IncomeGuidancePlan) => void;
}

const HEADING_ID = "income-guidance-heading";

const feeLabel = (plan: IncomeGuidancePlan) =>
  `${currencyCodeToSymbol(plan.currency)}${plan.amount} ${plan.frequency}`;

/**
 * Income bands against the plans they suggest, so people can see which tier
 * fits their income. Choosing a row selects that plan.
 */
export const IncomeGuidance: FC<IncomeGuidanceProps> = ({
  groupedPlans,
  selected,
  onSelect
}) => {
  if (!getEnv("INCOME_GUIDANCE_ENABLED")) {
    return null;
  }

  // One row per tier. A tier offered in several currencies shows whichever
  // currency is currently chosen.
  const rows = Object.values(groupedPlans)
    .map((group) => group.find((plan) => plan.value === selected) || group[0])
    .filter((plan) => plan.incomeWeekly || plan.incomeMonthly);

  if (!rows.length) {
    return null;
  }

  const showWeekly = rows.some((plan) => plan.incomeWeekly);
  const showMonthly = rows.some((plan) => plan.incomeMonthly);
  const heading = getEnvStr("INCOME_GUIDANCE_HEADING");
  const note = getEnvStr("INCOME_GUIDANCE_NOTE");

  return (
    <section className="income-guidance form-section">
      {heading && (
        <p id={HEADING_ID} className="income-guidance-heading">
          {heading}
        </p>
      )}
      <table
        className="income-guidance-table"
        {...(heading
          ? { "aria-labelledby": HEADING_ID }
          : { "aria-label": "Suggested dues by income" })}
      >
        <thead>
          <tr>
            {showWeekly && <th scope="col">Weekly income</th>}
            {showMonthly && <th scope="col">Monthly income</th>}
            <th scope="col">Suggested dues</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((plan) => {
            const chosen = plan.value === selected;
            return (
              <tr
                key={plan.value}
                className={chosen ? "selected" : undefined}
                onClick={() => onSelect(plan)}
              >
                {showWeekly && <td data-label="Weekly income">{plan.incomeWeekly}</td>}
                {showMonthly && <td data-label="Monthly income">{plan.incomeMonthly}</td>}
                <td data-label="Suggested dues">
                  <button
                    type="button"
                    className="income-guidance-choose"
                    aria-pressed={chosen}
                    aria-label={`Choose ${feeLabel(plan)}`}
                    onClick={(event) => {
                      event.stopPropagation();
                      onSelect(plan);
                    }}
                  >
                    {feeLabel(plan)}
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {note && (
        <div
          className="income-guidance-note"
          dangerouslySetInnerHTML={{ __html: note }}
        ></div>
      )}
    </section>
  );
};
