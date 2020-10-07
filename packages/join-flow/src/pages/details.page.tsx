import React from "react";
import { Col, Container, Form, Row, Button, Collapse } from "react-bootstrap";
import { useForm } from "react-hook-form";
import isoCountries from 'iso-3166'

import { StagerComponent } from "../components/stager";
import { FormSchema } from "../schema";
import { useAddressLookup } from "../services/address-lookup.service";

export const DetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted,
}) => {
  const form = useForm({
    defaultValues: data,
  });
  const addressLookupForm = useForm();
  const addressLookup = useAddressLookup(form);
  const handleLookupPostcode = addressLookupForm.handleSubmit(({ postcode }) =>
    addressLookup.setPostcode(postcode)
  );

  return (
    <Container as="form" onSubmit={form.handleSubmit(onCompleted)}>
      <section className="form-section">
        <h2>Tell us more about you</h2>
        <p className="text-secondary">
          All fields marked with an asterisk (*) are required.
        </p>
        <Form.Group>
          <Form.Label>First Name</Form.Label>
          <Form.Control name="firstName" ref={form.register} />
        </Form.Group>
        <Form.Group>
          <Form.Label>Last Name</Form.Label>
          <Form.Control name="lastName" ref={form.register} />
        </Form.Group>
        <Form.Group>
          <Form.Label>Email Address</Form.Label>
          <Form.Control name="email" ref={form.register} />
        </Form.Group>
      </section>

      <section className="form-section">
        <h2>Date of birth</h2>
        <Row>
          <Col>
            <Form.Group>
              <Form.Label>Day</Form.Label>
              <Form.Control name="dobDay" ref={form.register} />
            </Form.Group>
          </Col>
          <Col>
            <Form.Group>
              <Form.Label>Month</Form.Label>
              <Form.Control name="dobMonth" ref={form.register} />
            </Form.Group>
          </Col>
          <Col>
            <Form.Group>
              <Form.Label>Year</Form.Label>
              <Form.Control name="dobYear" ref={form.register} />
            </Form.Group>
          </Col>
        </Row>
      </section>

      <section className="form-section">
        <h2>Contact details</h2>
        <Form.Group>
          <Form.Label>Postcode</Form.Label>
          <Form.Control name="postcode" ref={addressLookupForm.register} />
        </Form.Group>

        <Button className="mt-2" onClick={handleLookupPostcode}>
          Find address
        </Button>

        <Collapse in={!!addressLookup.options}>
          <Form.Group>
            <Form.Label>Select Address</Form.Label>
            <Form.Control
              as="select"
              custom
              className="form-control"
              onChange={(e) => addressLookup.setAddress(e.currentTarget.value)}
            >
              <option />

              {addressLookup.options?.map((opt) => (
                <option key={opt.id} value={opt.id}>
                  {opt.toString()}
                </option>
              ))}
            </Form.Control>
          </Form.Group>
        </Collapse>

        <Collapse in={!!addressLookup.address || !!data.addressLine1}>
          <div>
            <Form.Group>
              <Form.Label>Address line 1</Form.Label>
              <Form.Control name="addressLine1" ref={form.register} />
            </Form.Group>
            <Form.Group>
              <Form.Label>Address line 2</Form.Label>
              <Form.Control name="addressLine2" ref={form.register} />
            </Form.Group>
            <Form.Group>
              <Form.Label>City</Form.Label>
              <Form.Control name="addressCity" ref={form.register} />
            </Form.Group>
            <Form.Group>
              <Form.Label>County</Form.Label>
              <Form.Control name="addressCounty" ref={form.register} />
            </Form.Group>
            <Form.Group>
              <Form.Label>Postcode</Form.Label>
              <Form.Control name="addressPostcode" ref={form.register} />
            </Form.Group>
            <Form.Group>
              <Form.Label>Country</Form.Label>
              <Form.Control
                name="addressCountry"
                as="select"
                custom
                className="form-control"
                ref={form.register}
              >
                {isoCountries.map((c) => (
                  <option key={c.numeric} value={c.alpha2}>
                    {c.name}
                  </option>
                ))}
              </Form.Control>
            </Form.Group>
          </div>
        </Collapse>
      </section>

      <section className="form-section">
        <h2>Your privacy</h2>
        <p>
          The Green Party of England and Wales is committed to protecting your
          privacy, including online, and in the transparent use of any online
          information you give us in accordance with our legal obligations.
        </p>
        <p>
          Our Privacy Policy sets out in detail the purposes for which we
          process your personal data, who we share it with, what rights you have
          in relation to that data and everything else we think it's important
          for you to know.
        </p>
      </section>

      <Button className="form-section-addon" type="submit">
        Continue
      </Button>
    </Container>
  );
};
