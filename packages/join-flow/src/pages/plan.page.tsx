import React, { useEffect, useMemo } from "react";
import { Controller, useForm } from "react-hook-form";
import {
  ContinueButton,
  FormItem,
  PlanRadioPanel,
  RadioPanel
} from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { IncomeGuidance, IncomeGuidancePlan } from "../components/income-guidance";
import { FormSchema, currencyCodeToSymbol, getPaymentPlan } from "../schema";
import { get as getEnv, getStr as getEnvStr } from "../env";

const membershipTiersHeading = getEnvStr("MEMBERSHIP_TIERS_HEADING");
const membershipTiersCopy =
  getEnvStr("MEMBERSHIP_TIERS_COPY") ||
  "You can change or cancel whenever you want.";
const currencySelectionCopy = getEnvStr("CURRENCY_SELECTION_COPY");

export const PlanPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: data as {}
  });

  const membershipPlans = getEnv("MEMBERSHIP_PLANS") as any[];
  const groupedPlans: Record<string, any[]> = {};
  for (const plan of membershipPlans) {
    const group = groupedPlans[plan.label] || [];
    group.push(plan);
    groupedPlans[plan.label] = group;
  }

  // A currency selector is only rendered for plans available in more than one currency.
  const hasCurrencyChoice = Object.values(groupedPlans).some(
    (group) => group.length > 1
  );

  // Spell out the amount on the button so the member sees what they are about
  // to pay before leaving for a hosted payment page, which may not show it.
  const continueLabel = renderContinueLabel(
    form.watch("membership"),
    form.watch("customMembershipAmount")
  );

  // Matches choosing the tier itself, including resetting a custom amount.
  const selectPlan = (plan: IncomeGuidancePlan) => {
    form.setValue("membership", plan.value);
    setTimeout(() => {
      form.setValue("customMembershipAmount", plan.allowCustomAmount ? plan.amount : "");
    });
  };

  return (
    <form className="form-content" onSubmit={form.handleSubmit(onCompleted)}>
      <div>
        <Summary data={data} />
      </div>

      <IncomeGuidance
        groupedPlans={groupedPlans}
        selected={form.watch("membership")}
        onSelect={selectPlan}
      />

      <fieldset className="radio-grid form-section" role="radiogroup">
        <legend>
          <h2>{membershipTiersHeading}</h2>
        </legend>
        <div
          className="text-secondary"
          dangerouslySetInnerHTML={{ __html: membershipTiersCopy }}
        ></div>

        {hasCurrencyChoice && currencySelectionCopy && (
          <div
            className="text-secondary"
            dangerouslySetInnerHTML={{ __html: currencySelectionCopy }}
          ></div>
        )}

        {Object.keys(groupedPlans).map((label) => (
          <PlanRadioPanel
            key={label}
            name="membership"
            label={label}
            plans={groupedPlans[label]}
            form={form}
          />
        ))}
      </fieldset>

      <ContinueButton text={continueLabel} />
    </form>
  );
};

export const renderContinueLabel = (
  membership: string | undefined,
  customMembershipAmount: string | number | undefined
) => {
  const plan = getPaymentPlan(membership);
  // A redirecting plan sends people to a page instead of taking payment.
  if (!plan || plan.redirectUrl) {
    return "Continue";
  }
  const amount = plan.allowCustomAmount
    ? Number(customMembershipAmount || plan.amount)
    : Number(plan.amount);
  if (!(amount > 0)) {
    return "Continue";
  }
  const symbol = currencyCodeToSymbol(plan.currency);
  return `Continue and pay ${symbol}${amount} ${plan.frequency}`;
};
