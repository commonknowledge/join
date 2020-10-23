import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";

export const PaymentPage: StagerComponent = ({ data, onCompleted }) => {
  const form = useForm({
    defaultValues: data as {}
  });

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <section className="radio-grid form-section" role="radiogroup">
        <h1>How would you like to pay?</h1>
        <RadioPanel
          name="paymentMethod"
          value="directDebit"
          label="Direct Debit"
          valueText="£36 a year"
          description="Best for the party!"
          form={form}
        />
        <RadioPanel
          name="paymentMethod"
          value="creditCard"
          label="Credit or Debit Card"
          form={form}
        />
      </section>

      <ContinueButton />
    </form>
  );
};
