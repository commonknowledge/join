import React, { useState, useEffect } from "react";
import { Col, Form, Row, Button, Collapse } from "react-bootstrap";
import { useForm } from "react-hook-form";
import isoCountries from "iso-3166";

import { StagerComponent } from "../components/stager";
import { DetailsSchema, FormSchema, validate } from "../schema";
import { useAddressLookup } from "../services/address-lookup.service";
import { ContinueButton, FormItem } from "../components/atoms";

import { yupResolver } from "@hookform/resolvers/yup";
import * as yup from "yup";

const addressLookupFormSchema = yup.object().shape({
  postcode: yup.string().required("We need a postcode to search your postcode")
});

export const DetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: data as {},
    resolver: validate(DetailsSchema)
  });
  const [manuallyOpen, setAddressManuallyOpen] = useState(false);

  const addressLookupForm = useForm({
    resolver: yupResolver(addressLookupFormSchema)
  });
  const addressLookup = useAddressLookup(form);
  const handleLookupPostcode = addressLookupForm.handleSubmit(({ postcode }) =>
    addressLookup.setPostcode(postcode)
  );

  useEffect(() => {
    // Check if all we haven't touched the postcode at all or manually entered something, then give an error.
    if (
      Object.keys(form.errors).filter((error) => error.includes("address"))
        .length === 4
    ) {
      console.log("We have address errors");
      addressLookupForm.setError("postcode", {
        type: "manual",
        message:
          "Please fill in your address. Enter your postcode above then click on Find Address to find it, then use Select Address"
      });
    } else {
      addressLookupForm.clearErrors("postcode");
    }
  }, [form.errors]);

  return (
    <form
      className="form-content"
      noValidate
      onSubmit={form.handleSubmit(onCompleted)}
    >
      <section className="form-section">
        <h2>Tell us more about you</h2>
        <p className="text-secondary">
          All fields marked with an asterisk (*) are required.
        </p>
        <FormItem label="First Name" name="firstName" form={form} required>
          <Form.Control autoComplete="given-name" />
        </FormItem>
        <FormItem label="Last Name" name="lastName" form={form} required>
          <Form.Control autoComplete="family-name" />
        </FormItem>
      </section>

      <section className="form-section">
        <h2>Date of birth</h2>
        <p className="text-secondary">
          We collect every member's date of birth because our membership types
          are based on age.
        </p>
        <fieldset>
          <Row>
            <Col>
              <FormItem label="Day" name="dobDay" form={form} required>
                <Form.Control autoComplete="bday-day" />
              </FormItem>
            </Col>
            <Col>
              <FormItem label="Month" name="dobMonth" form={form} required>
                <Form.Control autoComplete="bday-month" />
              </FormItem>
            </Col>
            <Col>
              <FormItem label="Year" name="dobYear" form={form} required>
                <Form.Control autoComplete="bday-year" />
              </FormItem>
            </Col>
          </Row>
        </fieldset>
      </section>

      <section className="form-section">
        <h2>Home address</h2>
        <p className="text-secondary">
          We’ll use this to find your nearest local group and send your new
          membership card.
        </p>
        <FormItem
          label="Postcode"
          name="postcode"
          form={addressLookupForm}
          required
          after={
            <Button className="mt-2" onClick={handleLookupPostcode}>
              Find address
            </Button>
          }
        >
          <Form.Control className="mb-2" autoComplete="postal-code" />
        </FormItem>

        <p className="text-secondary">
          <a
            className="text-secondary text-decoration-underline cursor-pointer"
            onClick={() =>
              setAddressManuallyOpen((manuallyOpen) => !manuallyOpen)
            }
          >
            If you can't find your address, you can enter it manually.
          </a>
        </p>

        <Collapse in={!!addressLookup.options}>
          <Form.Group>
            <Form.Label>Select Address</Form.Label>
            <Form.Control
              as="select"
              custom
              className="form-control"
              onChange={(e) => addressLookup.setAddress(e.currentTarget.value)}
            >
              <option>Choose your address</option>

              {addressLookup.options?.map((opt) => (
                <option key={opt.id} value={opt.id}>
                  {opt.toString()}
                </option>
              ))}
            </Form.Control>
          </Form.Group>
        </Collapse>

        <Collapse
          in={!!addressLookup.address || !!data.addressLine1 || manuallyOpen}
        >
          <div>
            <FormItem label="Address line 1" name="addressLine1" form={form}>
              <Form.Control autoComplete="address-line-1" />
            </FormItem>
            <FormItem label="Address line 2" name="addressLine2" form={form}>
              <Form.Control autoComplete="address-line-2" />
            </FormItem>
            <FormItem label="City" name="addressCity" form={form}>
              <Form.Control autoComplete="address-level1" />
            </FormItem>
            <FormItem label="County" name="addressCounty" form={form}>
              <Form.Control />
            </FormItem>
            <FormItem label="Postcode" name="addressPostcode" form={form}>
              <Form.Control />
            </FormItem>
            <FormItem label="Country" form={form} name="addressCountry">
              <Form.Control autoComplete="country" as="select" custom className="form-control">
                {isoCountries.map((c) => (
                  <option key={c.numeric} value={c.alpha2}>
                    {c.name}
                  </option>
                ))}
              </Form.Control>
            </FormItem>
          </div>
        </Collapse>
      </section>

      <section className="form-section">
        <h2>Contact details</h2>
        <p className="text-secondary">
          We’ll use this to keep in touch about things that matter to you.
        </p>
        <FormItem label="Email Address" name="email" form={form} required>
          <Form.Control autoComplete="email" type="email" />
        </FormItem>
        <FormItem label="Phone number" name="phoneNumber" form={form} required>
          <Form.Control autoComplete="tel-national" type="tel" />
        </FormItem>
      </section>

      <section className="form-section">
        <h2>Password</h2>
        <p className="text-secondary">
          You'll be able to use this password to login to the Green Party
          website, its forums and vote in its elections.
        </p>
        <FormItem label="Password" name="password" form={form} required>
          <Form.Control type="password" />
        </FormItem>
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

      <ContinueButton />
    </form>
  );
};
