import React, { useRef } from "react";
import {
  CardCVV,
  CardExpiry,
  CardNumber,
  CardComponent,
} from "@chargebee/chargebee-js-react-wrapper";
import { Container, Button, Spinner, FormGroup, Form } from "react-bootstrap";
import { useForm } from "react-hook-form";

import { StagerComponent } from "../components/stager";
import { useCSSStyle } from "../hooks/util";
import { FormSchema } from "../schema";
import ddLogo from "../images/dd_logo_landscape.png";

Chargebee.init({
  site: process.env.REACT_APP_CHARGEBEE_SITE,
  publishableKey: process.env.REACT_APP_CHARGEBEE_KEY,
});

export const PaymentDetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted,
}) => {
  if (data.paymentMethod === "directDebit") {
    return <DirectDebitPaymentPage data={data} onCompleted={onCompleted} />;
  }
  if (data.paymentMethod === "creditCard") {
    return <CreditCardPaymentPage data={data} onCompleted={onCompleted} />;
  }

  return (
    <Container>
      <Spinner animation="grow" variant="primary" />
    </Container>
  );
};

const DirectDebitPaymentPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted,
}) => {
  const form = useForm({
    defaultValues: {
      ddAccountHolderName: [data.firstName, data.lastName].join(" "),
      ...data,
    },
  });

  return (
    <Container as="form" onSubmit={form.handleSubmit(onCompleted)}>
      <section className="form-section">
        <h2>Your bank details</h2>
        <Form.Group>
          <Form.Label>Account Name</Form.Label>
          <Form.Control name="ddAccountHolderName" ref={form.register} />
        </Form.Group>
        <Form.Group>
          <Form.Label>Account Number</Form.Label>
          <Form.Control name="ddAccountNumber" ref={form.register} />
        </Form.Group>
        <Form.Group>
          <Form.Label>Sort Code</Form.Label>
          <Form.Control name="ddSortCode" ref={form.register} />
        </Form.Group>
        <Form.Group>
          <Form.Check
            name="ddConfirmAccountHolder"
            ref={form.register}
            label="I confirm that I am the account holder and am authorised to set up Direct Debit payments on this account."
          />
        </Form.Group>
      </section>

      <section className="form-section">
        <img
          className="img-blend"
          alt="Direct Debit logo"
          width={200}
          src={ddLogo}
        />
        <h2 className="mt-2">The Direct Debit Guarantee</h2>
        <ul className="text-sm">
          <li>
            The Guarantee is offered by all banks and building societies that
            accept instructions to pay Direct Debits
          </li>
          <li>
            If there are any changes to the amount, date or frequency of your
            Direct Debit the organisation will notify you (normally 10 working
            days) in advance of your account being debited or as otherwise
            agreed. If you request the organisation to collect a payment,
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
            back when the organisation asks you to
          </li>
          <li>
            You can cancel a Direct Debit at any time by simply contacting your
            bank or building society. Written confirmation may be required.
            Please also notify the organisation.
          </li>
        </ul>

        <p>
          <small>
            Direct debit payments are processed by GoCardless. Read the{" "}
            <a
              href="https://gocardless.com/legal/privacy/"
              rel="noopener noreferrer"
              target="_blank"
            >
              GoCardless privacy notice
            </a> for more information.
          </small>
        </p>
      </section>
      
      <Button className="form-section-addon" type="submit">
        Continue
      </Button>
    </Container>
  );
};

const CreditCardPaymentPage: StagerComponent<FormSchema> = ({
  onCompleted,
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
      paymentToken: token,
    });
  };

  return (
    <Container as="form" onSubmit={form.handleSubmit(handleCompleted)}>
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

      <Button className="form-section-addon" type="submit">
        Continue
      </Button>
    </Container>
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
  "fontVariant",
];
