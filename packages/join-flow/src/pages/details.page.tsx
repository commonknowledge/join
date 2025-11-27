import React, { useState, useEffect } from "react";
import { Col, Form, Row, Button, Collapse, Alert } from "react-bootstrap";
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

const requireAddress = Boolean(getEnv("REQUIRE_ADDRESS"));
const hideAddress = Boolean(getEnv("HIDE_ADDRESS"));
const homeAddressCopy = getEnvStr("HOME_ADDRESS_COPY");
const passwordPurpose = getEnvStr("PASSWORD_PURPOSE");
const privacyCopy = getEnvStr("PRIVACY_COPY");
const aboutYouHeading = getEnvStr("ABOUT_YOU_HEADING");
const aboutYouCopy = getEnvStr("ABOUT_YOU_COPY");
const contactDetailsHeading = getEnvStr("CONTACT_DETAILS_HEADING");
const contactConsentCopy = getEnvStr("CONTACT_CONSENT_COPY");
const contactDetailsCopy = getEnvStr("CONTACT_DETAILS_COPY");
const dateOfBirthHeading = getEnvStr("DATE_OF_BIRTH_HEADING");
const dateOfBirthCopy = getEnvStr("DATE_OF_BIRTH_COPY");
const customFields = (getEnv("CUSTOM_FIELDS") || []) as any[];
const customFieldsHeading = getEnvStr("CUSTOM_FIELDS_HEADING");
const useHearAboutUs = getEnv("COLLECT_HEAR_ABOUT_US") || false;
const hearAboutUsDetails = getEnvStr("HEAR_ABOUT_US_DETAILS");
const hearAboutUsHeading = getEnvStr("HEAR_ABOUT_US_HEADING");
const hearAboutUsOptions = (
  (getEnv("HEAR_ABOUT_US_OPTIONS") || []) as any[]
).filter((o) => o.toLowerCase() !== "other");

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
  const howDidYouHearAboutUs = form.watch("howDidYouHearAboutUs");

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
      setAddressManuallyOpen(true);
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
        <h2>{aboutYouHeading}</h2>
        <div
          className="text-secondary"
          dangerouslySetInnerHTML={{ __html: aboutYouCopy }}
        ></div>
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
              <h2>{dateOfBirthHeading}</h2>
            </legend>
            <div
              className="text-secondary"
              dangerouslySetInnerHTML={{ __html: dateOfBirthCopy }}
            ></div>
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

      {!hideAddress ? (
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

            {addressLookup.message && (
              <Alert 
                variant={addressLookup.messageType === 'error' ? 'warning' : 'info'} 
                className="mt-2"
              >
                <div dangerouslySetInnerHTML={{ __html: addressLookup.message }} />
              </Alert>
            )}

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

            <Collapse in={!!addressLookup.options && addressLookup.options.length > 0}>
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
            <FormItem label="Address line 1" name="addressLine1" form={form} required={requireAddress}>
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
            <FormItem label="City" name="addressCity" form={form} required={requireAddress}>
              <Form.Control
                autoComplete="address-level2"
                disabled={addressLookup.loading}
              />
            </FormItem>
            {getEnv("COLLECT_COUNTY") ? (
              <FormItem label="County" name="addressCounty" form={form} required>
                <Form.Control disabled={addressLookup.loading} />
              </FormItem>
            ) : null}

            <FormItem label="Postcode" name="addressPostcode" form={form} required={requireAddress}>
              <Form.Control disabled={addressLookup.loading} />
            </FormItem>
            <FormItem label="Country" form={form} name="addressCountry" required={requireAddress}>
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
      ) : null}

      {customFields.length ? (
        <section className="form-section">
          {customFieldsHeading ? <h2>{customFieldsHeading}</h2> : null}
          {customFields.map((field) => (
            <React.Fragment key={field.id}>
              {field.field_type === "checkbox" ? (
                <FormItem name={field.id} form={form} required={field.required}>
                  <Form.Check label={field.label} />
                </FormItem>
              ) : field.field_type === "select" ? (
                <FormItem label={field.label} name={field.id} form={form} required={field.required}>
                  <Form.Control as="select" custom className="form-control">
                    <option value="">Choose an option</option>
                    {parseCustomFieldOptions(field.options).map(
                      (o: { label: string; value: string }) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      )
                    )}
                  </Form.Control>
                </FormItem>
              ) : field.field_type === "radio" ? (
                <>
                  <span>{field.label}</span>
                  <FormItem
                    name={field.id}
                    form={form}
                    style={{ marginTop: "0.125rem" }}
                    required={field.required}
                  >
                    {parseCustomFieldOptions(field.options).map(
                      (o: { label: string; value: string }) => (
                        <Form.Check
                          key={o.value}
                          value={o.value}
                          name={field.id}
                          type="radio"
                          id={`${field.id}-${o.value}`}
                          label={o.label}
                        />
                      )
                    )}
                  </FormItem>
                </>
              ) : (
                <FormItem label={field.label} name={field.id} form={form} required={field.required}>
                  <Form.Control
                    autoComplete={field.id}
                    type={field.field_type}
                  />
                </FormItem>
              )}
              {field.instructions && (
                <div
                  className="text-secondary"
                  dangerouslySetInnerHTML={{ __html: field.instructions }}
                ></div>
              )}
            </React.Fragment>
          ))}
        </section>
      ) : null}

      <section className="form-section">
        <h2>{contactDetailsHeading}</h2>
        <div
          className="text-secondary"
          dangerouslySetInnerHTML={{ __html: contactDetailsCopy }}
        ></div>
        <FormItem label="Email Address" name="email" form={form} required>
          <Form.Control autoComplete="email" type="email" />
        </FormItem>
        <FormItem label="Phone number" name="phoneNumber" form={form} required={Boolean(getEnv("REQUIRE_PHONE_NUMBER"))}>
          <Form.Control autoComplete="tel-national" type="tel" />
        </FormItem>
        {getEnv("COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT") ? (
          <>
            <div
              className="text-secondary"
              dangerouslySetInnerHTML={{ __html: contactConsentCopy }}
            ></div>
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

      {useHearAboutUs ? (
        <section className="form-section">
          <h2>{hearAboutUsHeading}</h2>
          <FormItem name="howDidYouHearAboutUs" form={form}>
            <Form.Control as="select" custom className="form-control">
              <option value="">Choose an option</option>
              {hearAboutUsOptions.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
              <option value="other">Other</option>
            </Form.Control>
          </FormItem>
          {howDidYouHearAboutUs === "other" && (
            <FormItem
              name="howDidYouHearAboutUsDetails"
              form={form}
              label={hearAboutUsDetails}
            >
              <Form.Control
                autoComplete="howDidYouHearAboutUsDetails"
                as="textarea"
              />
            </FormItem>
          )}
        </section>
      ) : null}

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

const parseCustomFieldOptions = (options: string) => {
  return options
    .split("\n")
    .map((row) => row.trim())
    .filter(Boolean)
    .map((row) =>
      row
        .split(":")
        .map((c) => c.trim())
        .filter(Boolean)
    )
    .map((row) => {
      if (row.length === 0) {
        return null;
      }
      if (row.length === 1) {
        return { value: row[0], label: row[0] };
      }
      return { value: row[0], label: row.slice(1).join(":") };
    })
    .filter((r) => r !== null);
};
