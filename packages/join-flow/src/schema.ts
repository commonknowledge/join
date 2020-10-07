import { boolean, InferType, number, object, ObjectSchema, string } from 'yup'

const Prerequesites = object({
  /** Email passed in via query param */
  email: string().required(),

  /** Session token required by GoCardless. Generated client-side on load. */
  sessionToken: string().required()
})

const DetailsSchema = object({
  firstName: string().required(),
  lastName: string().required(),
  dobDay: number().integer().required(),
  dobMonth: number().integer().required(),
  dobYear: number().integer().required(),
  addressLine1: string().required(),
  addressLine2: string().required(),
  addressCity: string().required(),
  addressCounty: string().required(),
  addressPostcode: string().required(),
  addressCountry: string().required(),
}).required()

const PlanSchema = object({
  membership: string().oneOf(['standard', 'lowWaged', 'international', 'unwaged']).required()
}).required()

const PaymentMethodSchema = object({
  paymentMethod: string().oneOf(['directDebit', 'directDebit']).required()
}).required()

const PaymentDetailsSchema = object({
  paymentToken: string(),
  isReturningFromDirectDebitRedirect: boolean(),
}).required()

export const FormSchema: ObjectSchema<FormSchema> = object()
  .concat(Prerequesites)
  .concat(DetailsSchema)
  .concat(PlanSchema)
  .concat(PaymentMethodSchema)
  .concat(PaymentDetailsSchema)
  .required()

export type FormSchema = Partial<
  & InferType<typeof Prerequesites>
  & InferType<typeof DetailsSchema>
  & InferType<typeof PlanSchema>
  & InferType<typeof PaymentMethodSchema>
  & InferType<typeof PaymentDetailsSchema>
>

export const getTestDataIfEnabled = (): FormSchema => {
  if (process.env.REACT_APP_USE_TEST_DATA) {
    return {
      email: 'me@example.com',
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
    }
  } else {
    return {}
  }
}


export type DirectDebitSetupRequest = FormSchema & {
  redirectUrl: string
}

export interface DirectDebitSetupResult {
  redirectFlow: {
    id: string
    url: string
  }
}