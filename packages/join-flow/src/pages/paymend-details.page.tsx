import React, { useRef } from "react";
import {
  CardComponent,
  CardCVV,
  CardExpiry,
  CardNumber
} from "@chargebee/chargebee-js-react-wrapper";
import { Button, Form, FormGroup, Spinner } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, FormItem } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { useCSSStyle } from "../hooks/util";
import ddLogo from "../images/dd_logo_landscape.png";
import { PaymentMethodDDSchema, FormSchema, validate } from "../schema";

if (window.Chargebee) {
  window.Chargebee.init({
    site: process.env.REACT_APP_CHARGEBEE_SITE,
    publishableKey: process.env.REACT_APP_CHARGEBEE_KEY
  });
} else {
  console.error(
    "Chargebee library is not loaded in surrounding page. Chargebee React components will not function as a result.\n\nWhen the Green Party join form is loaded in WordPress, this should be loaded when the Join Form block is present on the page."
  );
}

export const PaymentDetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  // TODO: Fix Typescript error messages
  if (data.paymentMethod === "directDebit") {
    return <DirectDebitPaymentPage data={data} onCompleted={onCompleted} />;
  }
  if (data.paymentMethod === "creditCard") {
    return <CreditCardPaymentPage data={data} onCompleted={onCompleted} />;
  }

  return (
    <div className="form-content">
      <Spinner animation="grow" variant="primary" />
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

  const organisation = "GC re The Green Party";

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <section className="form-section">
        <h1>Your bank details</h1>
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
  onCompleted
}) => {
  const cardRef = useRef<any>();
  const form = useForm();
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
        ref={cardRef}
      >
        <FormGroup>
          <Form.Label>Card Number</Form.Label>
          <CardNumber />
        </FormGroup>
        <FormGroup>
          <Form.Label>Expiry</Form.Label>
          <CardExpiry />
        </FormGroup>
        <FormGroup>
          <Form.Label>CVV</Form.Label>
          <CardCVV />
        </FormGroup>
      </CardComponent>

      <ContinueButton />
    </form>
  );
};

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
