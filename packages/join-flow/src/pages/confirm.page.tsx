import React from "react";
import { Button, FormText } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

export const ConfirmationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm();

  const joiningSpinner = (
    <div className="d-flex justify-content-center align-items-center flex-column">
      <div className="spinner-border" role="status">
        <span className="sr-only">Please wait</span>
      </div>
      <div className="mt-3">Joining the Green Party</div>
    </div>
  );

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <section className="form-section mb-3">
        <h2>Confirm your details</h2>

        {form.formState.isSubmitting ? joiningSpinner : <Summary data={data} />}
      </section>

      <Button
        className="form-section-addon"
        type="submit"
        disabled={form.formState.isSubmitting}
      >
        Join the Greens
      </Button>
    </form>
  );
};
