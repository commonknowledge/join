import React, { useState, useEffect } from "react";
import { Col, Form, Row, Button, Collapse } from "react-bootstrap";
import { useForm } from "react-hook-form";

import { get as getEnv, getStr as getEnvStr } from "../env";
import { StagerComponent } from "../components/stager";
import { DetailsSchema, FormSchema, validate } from "../schema";
import { useAddressLookup } from "../services/address-lookup.service";
import { ContinueButton, FormItem } from "../components/atoms";
import { sortedCountries } from "../constants";

import { yupResolver } from "@hookform/resolvers/yup";
import * as yup from "yup";
import { redirectToSuccess } from "../services/router.service";
import { usePostResource } from "../services/rest-resource.service";

const addressLookupFormSchema = yup.object().shape({
  postcode: yup.string().required("We need a postcode to search your postcode")
});

const homeAddressCopy = getEnvStr("HOME_ADDRESS_COPY");
const passwordPurpose = getEnvStr("PASSWORD_PURPOSE");
const privacyCopy = getEnvStr("PRIVACY_COPY");

export const DetailsPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const form = useForm({
    defaultValues: data as {},
    resolver: validate(DetailsSchema)
  });
  const [manuallyOpen, setAddressManuallyOpen] = useState(false);
  const [skippingPayment, setSkippingPayment] = useState(false);
  const usePostcodeLookup = getEnv("USE_POSTCODE_LOOKUP");

  const addressLookupForm = useForm({
    resolver: yupResolver(addressLookupFormSchema)
  });
  const addressLookup = useAddressLookup(form);
  const handleLookupPostcode = addressLookupForm.handleSubmit(({ postcode }) =>
    addressLookup.setPostcode(postcode)
  );

  useEffect(() => {
    // Check if all we haven't touched the postcode at all or manually entered something, then give an error.
    if (form.errors && Object.keys(form.errors).length > 0) {
      setAddressManuallyOpen(true)
    }
    if (
      Object.keys(form.errors).filter((error) => error.includes("address"))
        .length === 4
    ) {
      addressLookupForm.setError("postcode", {
        type: "manual",
        message:
          "Please fill in your address. Enter your postcode above then click on Find Address to find it, then use Select Address"
      });
    } else {
      addressLookupForm.clearErrors("postcode");
    }
  }, [form.errors]);

  const recordStep =
    usePostResource<Partial<FormSchema & { stage: string }>>("/step");
  const skipPayment = async () => {
    setSkippingPayment(true);
    await recordStep({ ...data, stage: "enter-details" });
    await redirectToSuccess(data);
  };

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

      {getEnv("COLLECT_DATE_OF_BIRTH") ? (
        <section className="form-section">
          <fieldset>
            <legend>
              <h2>Date of birth</h2>
            </legend>

            <p className="text-secondary">
              We collect every member's date of birth because our membership
              types are based on age.
            </p>
            <Row>
              <Col>
                <FormItem label="Day" name="dobDay" form={form} required>
                  <Form.Control
                    autoComplete="bday-day"
                    placeholder="DD"
                    maxLength={2}
                  />
                </FormItem>
              </Col>
              <Col>
                <FormItem label="Month" name="dobMonth" form={form} required>
                  <Form.Control
                    autoComplete="bday-month"
                    placeholder="MM"
                    maxLength={2}
                  />
                </FormItem>
              </Col>
              <Col>
                <FormItem label="Year" name="dobYear" form={form} required>
                  <Form.Control
                    autoComplete="bday-year"
                    placeholder="YYYY"
                    maxLength={4}
                  />
                </FormItem>
              </Col>
            </Row>
          </fieldset>
        </section>
      ) : null}

      <section className="form-section">
        <h2>Home address</h2>
        {getEnv("HIDE_HOME_ADDRESS_COPY") ? (
          <div
            className="text-secondary"
            dangerouslySetInnerHTML={{ __html: homeAddressCopy }}
          ></div>
        ) : null}
        {usePostcodeLookup ? (
          <>
            <FormItem
              label="Postcode"
              name="postcode"
              form={addressLookupForm}
              required
              after={
                <Button
                  className="mt-2"
                  onClick={handleLookupPostcode}
                  variant="secondary"
                >
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
                  onChange={(e) =>
                    addressLookup.setAddress(e.currentTarget.value)
                  }
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
          </>
        ) : null}
        <Collapse
          in={
            !!addressLookup.address ||
            !!data.addressLine1 ||
            manuallyOpen ||
            !usePostcodeLookup
          }
        >
          <div>
            <FormItem label="Address line 1" name="addressLine1" form={form}>
              <Form.Control
                autoComplete="address-line1"
                disabled={addressLookup.loading}
              />
            </FormItem>
            <FormItem label="Address line 2" name="addressLine2" form={form}>
              <Form.Control
                autoComplete="address-line2"
                disabled={addressLookup.loading}
              />
            </FormItem>
            <FormItem label="City" name="addressCity" form={form}>
              <Form.Control
                autoComplete="address-level2"
                disabled={addressLookup.loading}
              />
            </FormItem>
            {getEnv("COLLECT_COUNTY") ? (
              <FormItem label="County" name="addressCounty" form={form}>
                <Form.Control disabled={addressLookup.loading} />
              </FormItem>
            ) : null}

            <FormItem label="Postcode" name="addressPostcode" form={form}>
              <Form.Control disabled={addressLookup.loading} />
            </FormItem>
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
        {getEnv("COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT") ? (
          <>
            <p className="text-secondary">
              How would you like us to contact you?
            </p>
            <FormItem form={form} name="contactByEmail">
              <Form.Check label="Email" />
            </FormItem>
            <FormItem form={form} name="contactByPhone">
              <Form.Check label="Phone" />
            </FormItem>
          </>
        ) : null}
      </section>

      {getEnv("CREATE_AUTH0_ACCOUNT") ? (
        <section className="form-section">
          <h2>Password</h2>
          {passwordPurpose ? (
            <div
              className="text-secondary"
              dangerouslySetInnerHTML={{ __html: passwordPurpose }}
            ></div>
          ) : (
            ""
          )}
          <p className="text-secondary">
            Your password should contain at least one number, one uppercase
            letter and one special character. It must be at least 8 characters
            long.
          </p>
          <FormItem label="Password" name="password" form={form} required>
            <Form.Control type="password" />
          </FormItem>
        </section>
      ) : null}

      <section className="form-section">
        <h2>How did you hear about us?</h2>
        <FormItem name="howDidYouHearAboutUs" form={form}>
          <Form.Control as="select" custom className="form-control">
            <option>Choose an option</option>

            <option>From another member</option>
            <option>An email from us</option>
            <option>Social media</option>
            <option>Press/radio</option>
            <option>TV</option>
            <option>Other</option>
          </Form.Control>
        </FormItem>
      </section>

      <section className="form-section">
        <div dangerouslySetInnerHTML={{ __html: privacyCopy }}></div>
      </section>

      <ContinueButton />
      {getEnv("INCLUDE_SKIP_PAYMENT_BUTTON") ? (
        <button
          type="button"
          className="mt-2 btn btn-secondary"
          onClick={skipPayment}
        >
          Skip payment
        </button>
      ) : null}
    </form>
  );
};
