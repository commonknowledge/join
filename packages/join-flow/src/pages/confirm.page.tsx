import React from "react";
import { Container, Button } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { StagerComponent } from "../components/stager";

export const ConfirmationPage: StagerComponent = ({ onCompleted }) => {
  const form = useForm();


  return (
    <Container as="form" onSubmit={form.handleSubmit(onCompleted)}>
      <section className="radio-grid form-section" role="radiogroup">
        <h2>Your membershp</h2>

        <p>
          Stuff goes here. Beep boop.
        </p>
      </section>

      <Button className="form-section-addon" type="submit">Join the Greens</Button>
    </Container>
  );
};
