import { memoize } from "lodash-es";
import { boolean, InferType, number, object, ObjectSchema, string } from "yup";
import "yup-phone";
import { yupResolver } from "@hookform/resolvers/yup";

import { getDaysInMonth, isPast } from "date-fns";
import { get as getEnv } from "./env";

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
  dobDay: getEnv('COLLECT_DATE_OF_BIRTH') ? number()
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
    .required() : number(),
  dobMonth: getEnv('COLLECT_DATE_OF_BIRTH') ? number()
    .typeError("The month of your birth must be a number")
    .integer("The month of your birth should be a whole number")
    .positive("The month of your birth should be a positive number")
    .max(
      12,
      "The month of your birth should be only be a number between 1 and 12, representing the months of the year"
    )
    .required() : number(),
  dobYear: getEnv('COLLECT_DATE_OF_BIRTH') ? number()
    .typeError("The year of your birth must be a number")
    .integer("The year of your birth should be a whole number")
    .positive("The year of your birth should be a positive number")
    .min(1900, "The year of your birth should not be in the distant past")
    .test(
      "is-not-in-future",
      "The date of your birth should not be in the future",
      isInPast
    )
    .required() : number(),
  addressLine1: string().required(),
  addressLine2: string(),
  addressCity: string().required(),
  addressCounty: getEnv('COLLECT_COUNTY') ? string().required() : string(),
  addressPostcode: string().required(),
  addressCountry: string().required(),
  password: getEnv('CREATE_AUTH0_ACCOUNT') ? string()
    .min(8, "Password must be at least 8 characters")
    .matches(/[0-9]/, "Password must contain a number.")
    .matches(/[A-Z]/, "Password must contain an uppercase letter.")
    .matches(
      /[!@#$%^&*]/,
      "Password must contain at least one special character, !@#$%^&* are allowed."
    )
    .required() : string(),
  phoneNumber: string()
    .phone("GB", false, "A valid phone number is required")
    .required(),
  contactByEmail: boolean(),
  contactByPhone: boolean()
}).required();

export const PlanSchema = object({
  membership: string()
    .oneOf([
      "standard",
      "lowWaged",
      "international",
      "unwaged",
      "student",
      "suggested"
    ])
    .required(),
  customMembershipAmount: number()
}).required();

const ucwords = (str: string) => {
  return str.split(' ').map(s => {
    return `${s.substring(0, 1).toLocaleUpperCase()}${s.substring(1)}`
  }).join(' ')
}

export const currencyCodeToSymbol = (code: string): string => {
  switch(code) {
    case 'EUR':
      return '€';
    case 'GBP':
      return '£';
    case 'USD':
      return '$';
  }
  return '£';
}

export const getPaymentPlan = (name: string | undefined) => {
  const plans = getEnv('MEMBERSHIP_PLANS')
  return (plans as any[]).filter(p => p.value === name).pop()
}

export const renderPaymentPlan = ({ membership, customMembershipAmount }: FormSchema) => {
  if (!membership) {
    return "None";
  }

  const plan = getPaymentPlan(membership)

  if (!plan || !plan.allowCustomAmount) {
    return ucwords(membership)
  }

  const amount = `${currencyCodeToSymbol(plan.currency)}${customMembershipAmount}`
  const parts = [ucwords(membership), amount, plan.frequency]
  return parts.join(', ')
};

const PaymentMethodSchema = object({
  paymentMethod: string().oneOf(["directDebit", "creditCard"]).required()
}).required();

export const getPaymentFrequency = (membership: string | undefined) => {
  const plan = getPaymentPlan(membership)
  return plan?.frequency || ''
}

export const renderPaymentMethod = ({
  paymentMethod,
  membership
}: FormSchema) => {
  let paymentFormat = "None";

  if (paymentMethod === "creditCard") {
    paymentFormat = "Credit or Debit Card";
  }

  if (paymentMethod === "directDebit") {
    paymentFormat = "Direct Debit";
  }

  const frequency = getPaymentFrequency(membership);

  if (frequency) {
    return `${paymentFormat}, ${frequency}`
  }

  return paymentFormat;
};

export const PaymentMethodDDSchema = object({
  ddAccountHolderName: string().required(),
  ddAccountNumber: string()
    .matches(
      /^(\d){8}$/,
      "A account number looks like eight digits. For example, 11223344"
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
  paymentToken: string().required()
}).required();

const PaymentDetailsSchema = PaymentMethodDDSchema.concat(
  PaymentMethodCardSchema
);

const DonationSchema = object({
  donationAmount: number().positive().integer(),
  recurDonation: boolean().default(false)
}).required();

const CustomGoCardlessSchema = object({
  gcBillingRequestId: string()
})

export const FormSchema: ObjectSchema<FormSchema> = object()
  .concat(Prerequesites)
  .concat(DetailsSchema)
  .concat(PlanSchema)
  .concat(DonationSchema)
  .concat(PaymentMethodSchema)
  .concat(PaymentDetailsSchema)
  .concat(CustomGoCardlessSchema)
  .required();

export type FormSchema = Partial<
  InferType<typeof Prerequesites> &
  InferType<typeof DetailsSchema> &
  InferType<typeof PlanSchema> &
  InferType<typeof DonationSchema> &
  InferType<typeof PaymentMethodSchema> &
  (
    | InferType<typeof PaymentMethodDDSchema>
    | InferType<typeof PaymentMethodCardSchema>
  ) &
  InferType<typeof PaymentDetailsSchema> &
  InferType<typeof CustomGoCardlessSchema>
>;

export const getTestDataIfEnabled = (): FormSchema => {
  const useTestData = getEnv('USE_TEST_DATA');
  if (useTestData) {
    console.log(
      "REACT_APP_USE_TEST_DATA environment variable set. Using test data."
    );
    return {
      email: "someone@example.com",
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
      ddAccountNumber: "55779911",
      ddSortCode: "200000",
      phoneNumber: "02036919400"
    };
  } else {
    return {};
  }
};

export const validate = memoize((schema: ObjectSchema) => yupResolver(schema));
