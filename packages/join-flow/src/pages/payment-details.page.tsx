import React, { useRef, useState } from "react";
import {
  CardComponent,
  CardCVV,
  CardExpiry,
  CardNumber
} from "@chargebee/chargebee-js-react-wrapper";
import {
  PaymentElement,
  useStripe,
  useElements,
  Elements
} from "@stripe/react-stripe-js";
import { Form, FormGroup, Spinner } from "react-bootstrap";
import { useForm } from "react-hook-form";
import * as Sentry from "@sentry/react";
import { ContinueButton, FormItem } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { sortedCountries } from "../constants";
import { useCSSStyle } from "../hooks/util";
import ddLogo from "../images/dd_logo_landscape.png";
import { PaymentMethodDDSchema, FormSchema, validate } from "../schema";

import { get as getEnv, getStr as getEnvStr } from "../env";
import { loadStripe } from "@stripe/stripe-js";
import { usePostResource } from "../services/rest-resource.service";
import { SAVED_STATE_KEY } from "../services/router.service";

export const PaymentDetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const renderForm = () => {
    if (data.paymentMethod === "directDebit") {
      return <DirectDebitPaymentPage data={data} onCompleted={onCompleted} />;
    }
    if (data.paymentMethod === "creditCard") {
      if (getEnv("USE_CHARGEBEE")) {
        return <CreditCardPaymentPage data={data} onCompleted={onCompleted} />;
      }
      if (getEnv("USE_STRIPE")) {
        return <StripePaymentPage data={data} onCompleted={onCompleted} />;
      }
      return <p>Error: no payment providers available. Please contact us.</p>;
    }
  };

  return (
    <div className="ml-4">
      <div className="mb-4">
        <Summary data={data} />
      </div>
      {renderForm()}
    </div>
  );
};

const DirectDebitPaymentPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: {
      ddAccountHolderName: [data.firstName, data.lastName].join(" "),
      ...data
    },
    resolver: validate(PaymentMethodDDSchema)
  });
  const organisation = getEnv("ORGANISATION_NAME");

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <section className="form-section">
        <h2>Your bank details</h2>
        <FormItem form={form} label="Account Name" name="ddAccountHolderName">
          <Form.Control />
        </FormItem>
        <FormItem form={form} label="Account Number" name="ddAccountNumber">
          <Form.Control />
        </FormItem>
        <FormItem form={form} label="Sort Code" name="ddSortCode">
          <Form.Control />
        </FormItem>
        <FormItem form={form} name="ddConfirmAccountHolder">
          <Form.Check label="I confirm that I am the account holder and am authorised to set up Direct Debit payments on this account." />
        </FormItem>
        {getEnv("IS_UPDATE_FLOW") ? (
          <FormItem label="Country" form={form} name="addressCountry">
            <Form.Control
              autoComplete="country"
              as="select"
              className="form-control"
            >
              {sortedCountries.map((c) => (
                <option key={c.numeric} value={c.alpha2}>
                  {c.name}
                </option>
              ))}
            </Form.Control>
          </FormItem>
        ) : null}
      </section>

      <section className="form-section">
        <img
          className="img-blend"
          alt="Direct Debit logo"
          width={200}
          src={ddLogo}
        />
        <h2 className="mt-2">The Direct Debit Guarantee</h2>
        <ul className="fineprint">
          <li>
            The Guarantee is offered by all banks and building societies that
            accept instructions to pay Direct Debits
          </li>
          <li>
            If there are any changes to the amount, date or frequency of your
            Direct Debit {organisation} will notify you (normally 3 working
            days) in advance of your account being debited or as otherwise
            agreed. If you request {organisation} to collect a payment,
            confirmation of the amount and date will be given to you at the time
            of the request
          </li>
          <li>
            If an error is made in the payment of your Direct Debit, by the
            organisation or your bank or building society, you are entitled to a
            full and immediate refund of the amount paid from your bank or
            building society
          </li>
          <li>
            If you receive a refund you are not entitled to, you must pay it
            back when {organisation} asks you to
          </li>
          <li>
            You can cancel a Direct Debit at any time by simply contacting your
            bank or building society. Written confirmation may be required.
            Please also notify the organisation.
          </li>
        </ul>

        <p className="fineprint">
          Direct debit payments are processed by GoCardless. Read the{" "}
          <a
            href="https://gocardless.com/legal/privacy/"
            rel="noopener noreferrer"
            target="_blank"
          >
            GoCardless privacy notice
          </a>{" "}
          for more information.
        </p>
      </section>

      <ContinueButton />
    </form>
  );
};

const CreditCardPaymentPage: StagerComponent<FormSchema> = ({
  onCompleted,
  data
}) => {
  const organisationName = getEnv("ORGANISATION_NAME");

  const cardRef = useRef<any>();
  const form = useForm();

  const chargebeeStylePropsList = [
    "color",
    "letterSpacing",
    "textAlign",
    "textTransform",
    "textDecoration",
    "textShadow",
    "fontFamily",
    "fontWeight",
    "fontSize",
    "fontSmoothing",
    "fontSmoothing",
    "fontSmoothing",
    "fontStyle",
    "fontVariant"
  ];

  const inputStyle = useCSSStyle(
    "form-control",
    "input",
    chargebeeStylePropsList
  );

  const handleCompleted = async () => {
    const { token } = await cardRef.current.tokenize();
    onCompleted({
      paymentToken: token
    });
  };

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(handleCompleted)}
    >
      <CardComponent
        className="form-section"
        styles={{ base: inputStyle }}
        classes={{
          invalid: "is-invalid"
        }}
        ref={cardRef}
      >
        <h2>Card details</h2>
        <p className="text-secondary mb-5">
          You've chosen to join {organisationName} by paying by card.
        </p>
        <FormGroup className="mb-5">
          <Form.Label>Card Number</Form.Label>
          <p className="text-secondary">
            The long number on the front of your card.
          </p>
          <CardNumber className="form-control" />
          <Form.Control.Feedback type="invalid">
            This doesn't look like a valid credit or debit card number.
          </Form.Control.Feedback>
        </FormGroup>
        <FormGroup className="mb-5">
          <Form.Label>Card Expiry</Form.Label>
          <p className="text-secondary">Should be on the front of your card.</p>
          <CardExpiry className="form-control" />
          <Form.Control.Feedback type="invalid">
            This doesn't look like a valid credit or debit card expiry date. It
            should a date in the future.
          </Form.Control.Feedback>
        </FormGroup>
        <FormGroup className="mb-5">
          <Form.Label>CVV</Form.Label>
          <p className="text-secondary">
            The three number security code on the back of your card.
          </p>
          <CardCVV className="form-control" />
          <Form.Control.Feedback type="invalid">
            This doesn't look like a valid credit or debit card CVV - it's
            normally three numbers on the back of your card.
          </Form.Control.Feedback>
        </FormGroup>
      </CardComponent>

      <ContinueButton />
    </form>
  );
};

const convertCurrencyFromMajorToMinorUnits = (amount: number) => amount * 100;

const StripePaymentPage: StagerComponent<FormSchema> = ({
  onCompleted,
  data
}) => {
  const stripePromise = loadStripe(getEnv("STRIPE_PUBLISHABLE_KEY") as string);
  const plan = (getEnv("MEMBERSHIP_PLANS") as any[]).find(
    (plan) => plan.value === data.membership
  );
  const amount = plan.amount
    ? convertCurrencyFromMajorToMinorUnits(plan.amount)
    : 100;
  const currency = plan.currency.toLowerCase() || "gbp";
  const paymentMethodTypes = getEnv("STRIPE_DIRECT_DEBIT_ONLY") ? ["bacs_debit"] : ["card"];
  // Add direct debit payment method for GBP only, as it is a UK only feature
  // Only add if not in Direct Debit-only mode (as it's already the only option)
  if (currency === "gbp" && getEnv("STRIPE_DIRECT_DEBIT") && !getEnv("STRIPE_DIRECT_DEBIT_ONLY")) {
    paymentMethodTypes.push("bacs_debit");
  }
  
  return (
    <Elements
      stripe={stripePromise}
      options={{
        paymentMethodCreation: "manual",
        mode: "subscription",
        amount,
        currency,
        paymentMethodTypes,
      }}
    >
      <StripeForm onCompleted={onCompleted} data={data} plan={plan} />
    </Elements>
  );
};

const StripeForm = ({
  onCompleted,
  data,
  plan
}: {
  onCompleted: (data: FormSchema) => void;
  data: FormSchema;
  plan: { frequency: string };
}) => {
  const stripe = useStripe();
  const elements = useElements();
  const createSubscription = usePostResource<
    FormSchema,
    {
      id: string;
      customer: string;
      latest_invoice: { payment_intent: { id: string; client_secret: string } };
    }
  >("/stripe/create-subscription");

  const [errorMessage, setErrorMessage] = useState<string | undefined>();
  const [loading, setLoading] = useState<boolean>(false);

  const handleError = (error: { message: string }) => {
    setLoading(false);
    setErrorMessage(error.message);
    Sentry.captureMessage(error.message, 'error')
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!stripe || !elements) {
      return;
    }

    setLoading(true);

    elements.submit();

    try {
      const subscription = await createSubscription({ ...data });
      const clientSecret =
        subscription.latest_invoice.payment_intent.client_secret;

      sessionStorage.setItem(
        SAVED_STATE_KEY,
        JSON.stringify({
          ...data,
          stripeCustomerId: subscription.customer,
          stripeSubscriptionId: subscription.id,
          stripePaymentIntentId: subscription.latest_invoice.payment_intent.id
        })
      );

      const returnUrl = new URL(window.location.href);
      returnUrl.searchParams.set("stripe_success", "true");

      const { error } = await stripe.confirmPayment({
        elements,
        clientSecret,
        confirmParams: {
          return_url: returnUrl.toString()
        }
      });

      if (error) {
        const message = JSON.stringify(error)
        handleError({ message });
        return;
      }
    } catch (e) {
      console.error("Create subscription error", e);
      handleError({ message: "Unknown error" });
      Sentry.captureException(e)
    }
  };

  // Pre-fill billing details from the form data
  // This allows users to verify their details are correct when setting up Direct Debit
  const defaultValues = {
    billingDetails: {
      name: data.firstName && data.lastName
        ? `${data.firstName} ${data.lastName}`
        : undefined,
      email: data.email || undefined,
      phone: data.phoneNumber || undefined,
      address: {
        line1: data.addressLine1 || undefined,
        line2: data.addressLine2 || undefined,
        city: data.addressCity || undefined,
        postal_code: data.addressPostcode || undefined,
        country: data.addressCountry || undefined,
      }
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <PaymentElement options={{defaultValues}}/>
        <ContinueButton disabled={loading} text={loading ? "Loading..." : ""} />
        {errorMessage && <div>{errorMessage}</div>}
      </div>
    </form>
  );
};
