import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

export const PlanPage: StagerComponent<FormSchema> = ({ data, onCompleted }) => {
  const form = useForm({
    defaultValues: data as {}
  });

  return (
    <Container as="form" noValidate onSubmit={form.handleSubmit(onCompleted)}>
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <section className="radio-grid form-section" role="radiogroup">
        <h2>Choose the plan that’s right for you</h2>
        <p className="text-secondary">
          You can change or cancel whenever you want.
        </p>

        <RadioPanel
          name="membership"
          value="standard"
          label="Standard Membership"
          valueText="£36 a year"
          valueMeta="or £3 a month"
          description="Available to everyone."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="lowWaged"
          label="Low–Waged Membership"
          valueText="£12 a year"
          description="If you are in low-waged employment."
          form={form}
        />
        <RadioPanel
          name="membership"
          value="international"
          label="International Membership"
          description="If you live outside of England or Wales."
          valueText="12 a year"
          form={form}
        />
        <RadioPanel
          name="membership"
          value="unwaged"
          valueText="£6 a year"
          label="Unwaged or Student Membership"
          description="If you are a student or not in employment."
          form={form}
        />
      </section>

      <Button className="form-section-addon" type="submit">Continue</Button>
    </Container>
  );
};
