import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";

export const PaymentPage: StagerComponent = ({ data, onCompleted }) => {
  const form = useForm({
    defaultValues: data as {}
  });

  return (
    <Container as="form" onSubmit={form.handleSubmit(onCompleted)}>
      <section className="radio-grid form-section" role="radiogroup">
        <h2>How would you like to pay?</h2>
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

      <Button className="form-section-addon" type="submit">Continue</Button>
    </Container>
  );
};
