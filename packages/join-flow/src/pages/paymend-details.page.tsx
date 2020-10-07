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
import { useCSSStyle, useOnce } from "../hooks/util";
import {
  DirectDebitSetupRequest,
  DirectDebitSetupResult,
  FormSchema,
} from "../schema";
import { usePostResource } from "../services/rest-resource.service";

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
  const prepareDD = usePostResource<
    DirectDebitSetupRequest,
    DirectDebitSetupResult
  >("/gocardless");

  // TODO: Replace this with a custom direct debit form
  useOnce(() => {
    if (data.isReturningFromDirectDebitRedirect) {
      onCompleted({
        isReturningFromDirectDebitRedirect: false,
      });
    } else {
      prepareDD({
        ...data,
        redirectUrl: window.location.href,
      }).then(({ redirectFlow }) => {
        window.location.href = redirectFlow.url;
      });
    }
  });

  return null;
};

const CreditCardPaymentPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted,
}) => {
  const cardRef = useRef<any>();
  const form = useForm();
  const inputStyle = useCSSStyle("form-control", "input", chargebeeStylePropsList);

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
