import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, RadioPanel } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

export const DonationPage: StagerComponent<FormSchema> = ({
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

      <fieldset className="form-section">
        <legend className="text-md">Can you chip in?</legend>
        <p className="text-secondary">
          If 2,500 people give £20 each we can lay the groundwork for change
          which will lead Britain to Build Back Better, with a Just Recovery
          that uplifts everyone, especially those marginalised by our systems.
        </p>
      </fieldset>

      <ContinueButton />
    </form>
  );
};
