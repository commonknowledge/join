import React, { useState } from "react";
import { Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, membershipIsAnnual } from "../schema";
import { usePostResource } from "../services/rest-resource.service";

export const ConfirmationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const organisationName = "The Green Party";
  const organisationEmailAddress = "membership@greenparty.org.uk";
  const organisationMailToLink = `mailto:${organisationEmailAddress}`;

  const form = useForm();
  const join = usePostResource<FormSchema>("/join");

  const [requestInFlight, setRequestInFlight] = useState(false);
  const [joinError, setJoinError] = useState(false);

  const joiningSpinner = (
    <div className="d-flex justify-content-center align-items-center flex-column h-200px">
      <div className="spinner-border" role="status">
        <span className="sr-only">Please wait</span>
      </div>
      <div className="mt-3">Joining The Green Party</div>
    </div>
  );

  let directDebitDetailsMessage = null;

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

  const onSubmit = async (data: FormSchema) => {
    setRequestInFlight(true);
    join(data).then(
      (res) => {
        onCompleted(data);
      },
      (error) => {
        setRequestInFlight(false);
        console.error(error.message);
        setJoinError(true);
      }
    );
  };

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onSubmit)}
    >
      <section className="form-section mb-3">
        <h1>Confirm your details</h1>
        {joinError && (
          <div className="alert alert-danger" role="alert">
            <p>Sorry you cannot join {organisationName} at this time.</p>
            <p>Please try again in an hour.</p>
            <p>
              If you continue to have problems please contact{" "}
              <a href={organisationMailToLink}>{organisationEmailAddress}</a>
            </p>
          </div>
        )}
        {requestInFlight ? (
          joiningSpinner
        ) : (
          <>
            <Summary data={data} />
            {directDebitDetailsMessage}
          </>
        )}
      </section>
      <Button
        className="form-section-addon text-bebas text-uppercase"
        type="submit"
        disabled={requestInFlight}
      >
        Join {organisationName}
      </Button>
    </form>
  );
};
