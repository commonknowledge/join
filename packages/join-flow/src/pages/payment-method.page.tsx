import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, renderPaymentMethod, renderPaymentPlan } from "../schema";

export const PaymentPage: StagerComponent = ({ data, onCompleted }) => {
  const form = useForm({
    defaultValues: data as FormSchema
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

      <fieldset className="radio-grid form-section" role="radiogroup">
        <legend className="text-md">How would you like to pay?</legend>
        <RadioPanel
          name="paymentMethod"
          value="directDebit"
          label="Direct Debit"
          description="Best for the party!"
          form={form}
        />
        <RadioPanel
          name="paymentMethod"
          value="creditCard"
          label="Credit or Debit Card"
          form={form}
        />
      </fieldset>

      <ContinueButton />
    </form>
  );
};
