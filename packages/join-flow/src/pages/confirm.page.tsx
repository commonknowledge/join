import React from "react";
import { Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, membershipIsAnnual } from "../schema";

export const ConfirmationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm();

  const joiningSpinner = (
    <div className="d-flex justify-content-center align-items-center flex-column h-200px">
      <div className="spinner-border" role="status">
        <span className="sr-only">Please wait</span>
      </div>
      <div className="mt-3">Joining the Green Party</div>
    </div>
  );

  let directDebitDetailsMessage = null;

  const organisationName = "The Green Party";
  const organisationEmailAddress = "membership@greenparty.org.uk";
  const organisationMailToLink = `mailto:${organisationEmailAddress}`;

  if (data.paymentMethod === "directDebit" && data.membership) {
    directDebitDetailsMessage = (
      <section className="form-section mb-3">
        <p>
          You are paying for your membership of The Green Party by Direct Debit.
        </p>
        <p>
          This will be charged every{" "}
          {membershipIsAnnual(data.membership) ? "year" : "month"} from your
          bank account.
        </p>
        <p>
          On your bank statement the charge will appear as "GC RE{" "}
          {organisationName}".
        </p>
        <p className="fineprint">
          You can contact the membership team of {organisationName} at{" "}
          <a href={organisationMailToLink}>{organisationEmailAddress}</a>
        </p>
      </section>
    );
  }

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <section className="form-section mb-3">
        <h1>Confirm your details</h1>

        {form.formState.isSubmitting ? joiningSpinner : <Summary data={data} />}
      </section>
      {directDebitDetailsMessage}
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
