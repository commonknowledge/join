import React from "react";
import { useForm } from "react-hook-form";
import { ContinueButton, RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, currencyCodeToSymbol } from "../schema";
import { get as getEnv } from "../env"

export const PlanPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: data as {}
  });

  return (
    <form className="form-content" onSubmit={form.handleSubmit(onCompleted)}>
      <div>
        <Summary data={data} />
      </div>

      <fieldset className="radio-grid form-section" role="radiogroup">
        <legend>
          <h2>Choose the plan that’s right for you</h2>
        </legend>
        <p className="text-secondary">
          You can change or cancel whenever you want.
        </p>

        {(getEnv('MEMBERSHIP_PLANS') as any[]).map((plan) => (
          <RadioPanel
            key={plan.value}
            name="membership"
            value={plan.value}
            label={plan.label}
            allowCustomAmount={plan.allowCustomAmount}
            currencySymbol={currencyCodeToSymbol(plan.currency)}
            amount={plan.amount}
            frequency={plan.frequency}
            description={plan.description}
            form={form}
          />
        ))}
      </fieldset>

      <ContinueButton />
    </form>
  );
};
