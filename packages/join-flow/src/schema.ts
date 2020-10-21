import { memoize } from "lodash-es";
import { boolean, InferType, number, object, ObjectSchema, string } from "yup";
import "yup-phone";
import { yupResolver } from "@hookform/resolvers/yup";

import { getDaysInMonth, isPast } from "date-fns";

// Typescript support for Yup phone validation
declare module "yup" {
  export interface StringSchema {
    phone(
      countryCode?: string,
      strict?: boolean,
      errorMessage?: string
    ): StringSchema;
  }
}

const Prerequesites = object({
  /** Email passed in via query param */
  email: string().required(),

  /** Session token required by GoCardless. Generated client-side on load. */
  sessionToken: string().required()
}).required();

function isValidDayOfMonth(value: number | null | undefined | object) {
  const correctDaysInMonth = getDaysInMonth(
    new Date(this.options.parent.dobYear, this.options.parent.dobMonth - 1)
  );

  return value <= correctDaysInMonth;
}

function isInPast(value: number | null | undefined | object) {
  return isPast(
    new Date(
      this.options.parent.dobYear,
      this.options.parent.dobMonth - 1,
      this.options.parent.dobDay - 1
    )
  );
}

export const DetailsSchema = object({
  firstName: string().required("First name is required"),
  lastName: string().required("Second name is required"),
  email: string()
    .email("Your email address must be a valid email address")
    .required("Email address is required"),
  dobDay: number()
    .typeError("The day of your birth must be a number")
    .integer("The day of your birth should be a whole number")
    .positive("The day of your birth should be a positive number")
    .max(
      31,
      "The day of your birth should be a number between 1 and 31, representing the days of the month"
    )
    .test(
      "is-valid-day-of-month",
      "This is not a valid day in the month",
      isValidDayOfMonth
    )
    .required(),
  dobMonth: number()
    .typeError("The month of your birth must be a number")
    .integer("The month of your birth should be a whole number")
    .positive("The month of your birth should be a positive number")
    .max(
      12,
      "The month of your birth should be only be a number between 1 and 12, representing the months of the year"
    )
    .required(),
  dobYear: number()
    .typeError("The year of your birth must be a number")
    .integer("The year of your birth should be a whole number")
    .positive("The year of your birth should be a positive number")
    .min(1900, "The year of your birth should not be in the distant past")
    .test(
      "is-not-in-future",
      "The date of your birth should not be in the future",
      isInPast
    )
    .required(),
  addressLine1: string().required(),
  addressLine2: string(),
  addressCity: string().required(),
  addressCounty: string().required(),
  addressPostcode: string().required(),
  addressCountry: string().required(),
  phoneNumber: string()
    .phone(undefined, false, "A valid phone number is required")
    .required()
}).required();

const PlanSchema = object({
  membership: string()
    .oneOf(["standard", "lowWaged", "international", "unwaged"])
    .required()
}).required();

export const renderPaymentPlan = ({ membership }: FormSchema) => {
  if (membership === "standard") {
    return "Standard Membership";
  }
  if (membership === "international") {
    return "International Membership";
  }
  if (membership === "lowWaged") {
    return "Low-Waged Membership";
  }
  if (membership === "unwaged") {
    return "Unwaged or Student Membership";
  }

  return "None";
};

const PaymentMethodSchema = object({
  paymentMethod: string().oneOf(["directDebit", "creditCard"]).required()
}).required();

export const renderPaymentMethod = ({ paymentMethod }: FormSchema) => {
  if (paymentMethod === "creditCard") {
    return "Credit Card";
  }
  if (paymentMethod === "directDebit") {
    return "Monthly Direct Debit";
  }

  return "None";
};

export const PaymentMethodDDSchema = object({
  paymentMethod: string().equals(["directDebit"]).required(),
  ddAccountHolderName: string().required(),
  ddAccountNumber: string()
    .matches(
      /^(\d){8}$/,
      "A account number looks like eight digits. For example, 1122334455"
    )
    .required(),
  ddSortCode: string()
    .matches(
      /^(?!(?:0{6}|00-00-00))(?:\d{6}|\d\d-\d\d-\d\d)$/,
      "A valid sort code looks like six digits, which can be separated by hyphens. For example, 04-00-04"
    )
    .required(),
  ddConfirmAccountHolder: boolean().equals([true]).required()
}).required();

const PaymentMethodCardSchema = object({
  paymentMethod: string().equals(["creditCard"]).required(),
  paymentToken: string().required()
}).required();

const PaymentDetailsSchema = PaymentMethodDDSchema.concat(
  PaymentMethodCardSchema
);

export const FormSchema: ObjectSchema<FormSchema> = object()
  .concat(Prerequesites)
  .concat(DetailsSchema)
  .concat(PlanSchema)
  .concat(PaymentMethodSchema)
  .concat(PaymentDetailsSchema)
  .required();

export type FormSchema = Partial<
  InferType<typeof Prerequesites> &
    InferType<typeof DetailsSchema> &
    InferType<typeof PlanSchema> &
    (
      | InferType<typeof PaymentMethodDDSchema>
      | InferType<typeof PaymentMethodCardSchema>
    ) &
    InferType<typeof PaymentDetailsSchema>
>;

export const getTestDataIfEnabled = (): FormSchema => {
  if (process.env.REACT_APP_USE_TEST_DATA) {
    console.log(
      "REACT_APP_USE_TEST_DATA environment variable set. Using test data."
    );
    return {
      email: "me@example.com",
      addressCity: "Oxford",
      addressCountry: "GB",
      addressCounty: "Oxfordshire",
      addressLine1: "54 Abingdon Road",
      addressLine2: "",
      addressPostcode: "OX14PE",
      dobDay: 5,
      dobMonth: 12,
      dobYear: 1980,
      firstName: "Test",
      lastName: "Person",
      membership: "standard",
      paymentMethod: "directDebit",
      ddAccountNumber: " 55779911",
      ddSortCode: "200000",
      phoneNumber: "02036919400"
    };
  } else {
    return {};
  }
};

export const validate = memoize((schema: ObjectSchema) => yupResolver(schema));
