import React from "react";
import { Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

export const ConfirmationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted,
}) => {
  const form = useForm();

  return (
    <form className="form-content" noValidate onSubmit={form.handleSubmit(onCompleted)}>
      <section className="form-section mb-3">
        <h2>Confirm your details</h2>

        <Summary data={data} />
      </section>

      <Button className="form-section-addon" type="submit">
        Join the Greens
      </Button>
    </form>
  );
};
