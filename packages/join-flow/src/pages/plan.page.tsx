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
import { FormSchema } from "../schema";
import { get as getEnv, getStr as getEnvStr } from "../env";

const membershipTiersHeading = getEnvStr("MEMBERSHIP_TIERS_HEADING");
const membershipTiersCopy =
  getEnvStr("MEMBERSHIP_TIERS_COPY") ||
  "You can change or cancel whenever you want.";

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

  return (
    <form className="form-content" onSubmit={form.handleSubmit(onCompleted)}>
      <div>
        <Summary data={data} />
      </div>

      <fieldset className="radio-grid form-section" role="radiogroup">
        <legend>
          <h2>{membershipTiersHeading}</h2>
        </legend>
        <div
          className="text-secondary"
          dangerouslySetInnerHTML={{ __html: membershipTiersCopy }}
        ></div>

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

      <ContinueButton />
    </form>
  );
};
