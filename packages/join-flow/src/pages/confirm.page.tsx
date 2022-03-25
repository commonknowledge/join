import React, { useState, ReactElement } from "react";
import { Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, membershipIsAnnual } from "../schema";
import { usePostResource } from "../services/rest-resource.service";
import { upperFirst } from "lodash-es";

export const ConfirmationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const organisationName = "The Green Party";
  const organisationEmailAddress = "members@greenparty.org.uk";
  const organisationMailToLink = `mailto:${organisationEmailAddress}`;

  const form = useForm();

  const join = usePostResource<FormSchema>("/join");

  const [requestInFlight, setRequestInFlight] = useState(false);
  const [joinError, setJoinError] = useState<ReactElement | string | boolean>(
    false
  );

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

  const onSubmit = async () => {
    setRequestInFlight(true);
    join(data).then(
      () => {
        onCompleted(data);
      },
      (error) => {
        setRequestInFlight(false);
        console.error(error.message);

        const errorInformaton = JSON.parse(error.message);

        let message = <p>Please try again in an hour.</p>;

        switch (errorInformaton?.data?.error_code) {
          case 1:
            message = <p>Please try re-entering your payment details.</p>;
            break;
          case 2:
            message = (
              <p>
                We couldn't charge the account as it didn't have sufficient
                funds. Maybe try another card?
              </p>
            );
            break;
          case 3:
            message = (
              <>
                <p>Something is wrong with your Direct Debit mandate.</p>
                <ul>
                  {errorInformaton.data.fields.map((fieldInformation: any) => (
                    <li>
                      {upperFirst(fieldInformation.field)}{" "}
                      {fieldInformation.message}
                    </li>
                  ))}
                </ul>
              </>
            );

            break;
          case 4:
            message = (
              <p>
                We couldn't charge the account as the card you entered has
                expired. Maybe try another card?
              </p>
            );
            break;
          case 25:
            message = (
              <>
                <p>We seem to already have an active membership for you.</p>
                <p>
                  If you'd like to update your details, we recommend using the{" "}
                  <a href="https://greenparty.chargebeeportal.com/portal/v2/login">
                    Green Party membership management page
                  </a>
                  .
                </p>
                <p>Thanks for being a member already!</p>
              </>
            );
            break;
        }

        setJoinError(message);
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
        {requestInFlight ? (
          joiningSpinner
        ) : (
          <>
            <Summary data={data} />
            {directDebitDetailsMessage}
            {joinError && (
              <div className="alert alert-danger" role="alert">
                <p>Sorry you cannot join {organisationName} at this time.</p>
                {joinError}

                <p>
                  If you continue to have problems please contact{" "}
                  <a href={organisationMailToLink}>
                    {organisationEmailAddress}
                  </a>
                </p>
              </div>
            )}
            <Button
              className="form-section-addon text-bebas text-uppercase"
              type="submit"
              disabled={requestInFlight}
            >
              Join {organisationName}
            </Button>
          </>
        )}
      </section>
    </form>
  );
};
