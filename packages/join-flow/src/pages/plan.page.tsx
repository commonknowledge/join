import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

export const PlanPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: data as {}
  });

  return (
    <form className="form-content" onSubmit={form.handleSubmit(onCompleted)}>
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <fieldset className="radio-grid form-section" role="radiogroup">
        <legend className="text-md">Choose the plan that’s right for you</legend>
        <p className="text-secondary">
          You can change or cancel whenever you want.
        </p>

        <RadioPanel
          name="membership"
          value="suggested"
          label="Suggested Membership Contribution"
          valueText="£10 a month"
          description="For those who are able to do more to help progress the Green Movement."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="standard"
          label="Standard Membership"
          valueText="£3.33 a month"
          valueMeta="or £36 a year"
          description="Available to everyone."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="lowWaged"
          label="Reduced Rate Membership"
          valueText="£13 a year"
          description="If you are in low-waged employment."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="student"
          valueText="£6 a year"
          label="Student Membership"
          description="If you are a student."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="unwaged"
          valueText="£6 a year"
          label="Concessionary Membership"
          description="If you are without paid work."
          form={form}
        />
      </fieldset>

      <ContinueButton />
    </form>
  );
};
