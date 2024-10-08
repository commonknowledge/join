import { parse } from "querystring";

import * as uuid from "uuid";
import React, { FC, useCallback, useState, useEffect } from "react";

import { DetailsPage } from "./pages/details.page";
import { PaymentPage } from "./pages/payment-method.page";
import { PlanPage } from "./pages/plan.page";
import { DonationPage } from "./pages/donation.page";
import {
  PageState,
  RouterContext,
  StateRouter,
  redirectToSuccess,
  stripUrlParams,
  useStateRouter,
  SAVED_STATE_KEY
} from "./services/router.service";
import { useOnce } from "./hooks/util";
import { PaymentDetailsPage } from "./pages/payment-details.page";
import { Stager } from "./components/stager";
import { currencyCodeToSymbol, FormSchema, getTestDataIfEnabled } from "./schema";
import { ConfirmationPage } from "./pages/confirm.page";
import { get as getEnv, getPaymentMethods } from "./env";
import { usePostResource } from "./services/rest-resource.service";
import gocardless from "./images/gocardless.svg";
import chargebee from "./images/chargebee.png";

import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';

interface Stage {
  id: PageState["stage"],
  label: string
  breadcrumb: boolean
}

let stages: Stage[] = [
  { id: "enter-details", label: "Your Details", breadcrumb: true },
  { id: "plan", label: "Your Membership", breadcrumb: true },
  { id: "donation", label: "Can you chip in?", breadcrumb: false },
  { id: "payment-details", label: "Payment", breadcrumb: true },
  { id: "payment-method", label: "Payment", breadcrumb: false },
  { id: "confirm", label: "Confirm", breadcrumb: false }
];

if (getEnv('IS_UPDATE_FLOW')) {
  stages = stages.filter(s => s.id !== 'enter-details')
}

// Redirect to confirm page if ?gocardless_success === "true"
// because that is a redirect from GoCardless
// Also require a billing request ID to be present.
const searchParams = new URLSearchParams(window.location.search)
const gcRedirect = searchParams.get("gocardless_success") === "true"
const savedSession = JSON.parse(sessionStorage.getItem(SAVED_STATE_KEY) || "{}")
const gcBillingRequestId = savedSession['gcBillingRequestId']
let shouldRedirectToConfirm = gcRedirect && gcBillingRequestId

// @ts-ignore
const stripePromise = loadStripe(getEnv('STRIPE_PUBLISHABLE_KEY'));

const App = () => {
  const [data, setData] = useState(getInitialState);

  const router = useStateRouter(
    {
      stage: stages[0].id
    },
    stages
  );

  useEffect(function routerAnalytics() {
    try {
      // @ts-ignore
      // In case there is a posthog install in the parent website
      posthog?.capture("join flow navigation", { stage: router.state.stage })
    } catch (e) { }
  }, [router])

  if (shouldRedirectToConfirm) {
    router.setState({ stage: "confirm" });
    // Prevent infinite loop
    shouldRedirectToConfirm = false;
  }

  useOnce(stripUrlParams);

  const recordStep = usePostResource<Partial<FormSchema & { stage: string }>>("/step");
  const getGoCardlessRedirect = usePostResource<Partial<FormSchema & { redirectUrl: string }>>("/gocardless/auth");

  const currentIndex = stages.findIndex((x) => x.id === router.state.stage);
  const handlePageCompleted = useCallback(
    async (change: FormSchema) => {
      const nextData = {
        ...data,
        ...change
      } as FormSchema;

      const includeDonationPage = getEnv("ASK_FOR_ADDITIONAL_DONATION");

      setData(nextData);
      sessionStorage.setItem(SAVED_STATE_KEY, JSON.stringify(nextData));

      let nextStage = router.state.stage

      if (router.state.stage === "enter-details") {
        nextStage = "plan"
        // Send initial details to catch drop off
        await recordStep({ ...nextData, stage: 'enter-details' });
      } else if (router.state.stage === "plan") {
        nextStage = "donation"
      } else if (router.state.stage === "donation") {
        nextStage = "payment-method"
      } else if (router.state.stage === "payment-method") {
        nextStage = "payment-details"
      } else if (router.state.stage === "payment-details") {
        nextStage = "confirm"
      } else if (router.state.stage === "confirm") {
        router.reset()
        await redirectToSuccess(data)
      }

      if (nextStage === "donation" && !includeDonationPage) {
        nextStage = "payment-method"
      }

      if (nextStage === "payment-method" && getPaymentMethods().length < 2) {
        nextStage = "payment-details"
      }

      // Go to external GoCardless pages if not using the GoCardless API
      if (
        nextStage === "payment-details" &&
        nextData.paymentMethod === "directDebit" &&
        getEnv("USE_GOCARDLESS") &&
        !getEnv("USE_GOCARDLESS_API")
      ) {
        // Undo the transition to prevent flicker
        nextStage = router.state.stage;
        // Redirect to GoCardless
        const redirectUrl = encodeURI(window.location.href);

        const goCardlessHrefResponse: any = await getGoCardlessRedirect({
          ...nextData,
          redirectUrl
        });
        sessionStorage.setItem(
          SAVED_STATE_KEY,
          JSON.stringify({ ...nextData, gcBillingRequestId: goCardlessHrefResponse.gcBillingRequestId })
        );
        window.location.href = goCardlessHrefResponse.href;
      }

      router.setState({ stage: nextStage });
    },
    [router, data]
  );

  const paymentProviderLogos = getPaymentMethods().map((method) => {
    return method === "directDebit" ?
      <a href="https://gocardless.com" target="_blank">
        <img key={method} alt="GoCardless" src={gocardless} width="100px" />
      </a> :
      <a href="https://chargebee.com" target="_blank">
        <img key={method} alt="Chargebee" src={chargebee} width="100px" />
      </a>
  })

  // mode is going to have to be payment

  const options = {
    mode: 'subscription',
    amount: 1000,
    currency: 'gbp',
    paymentMethodCreation: 'manual',
    // Fully customizable with appearance API.
    appearance: {/*...*/ },
  };

  /*
  Start £4 a month, end at £40 a month

  Donations start at £10 and end at £100
  */

  // @ts-ignore
  const minimalJoinForm = <Elements stripe={stripePromise} options={options}>
    <MinimalJoinForm />
  </Elements>;

  const fullJoinForm = <>
    <RouterContext.Provider value={router}>
      <div className="progress-steps">
        <h6>Join Us</h6>
        <div className="progress-steps__secure">
          <p>🔒 Secure payment with<br />{paymentProviderLogos}</p>
        </div>
        <ul className="p-0 list-unstyled">
          {stages.map(
            (stage, i) =>
              stage.breadcrumb && (
                <li
                  key={stage.id}
                  className={`progress-step progress-step--${i < currentIndex ? 'done' : i === currentIndex ? 'current' : 'next'}`}
                >
                  <div>
                    <span className="progress-circle"></span>
                    <span className="progress-text">
                      {stage.label}
                    </span>
                  </div>
                  <div className="progress-line"></div>
                </li>
              )
          )}
        </ul>
      </div>

      <Stager
        stage={router.state.stage}
        data={data}
        onStageCompleted={handlePageCompleted}
        components={{
          "enter-details": DetailsPage,
          plan: PlanPage,
          donation: DonationPage,
          "payment-details": PaymentDetailsPage,
          "payment-method": PaymentPage,
          confirm: ConfirmationPage
        }}
        fallback={<Fail router={router} />}
      />
    </RouterContext.Provider>
  </>


  let form = fullJoinForm;

  if (getEnv('MINIMAL_JOIN_FORM')) {
    form = minimalJoinForm;
  }

  return form;
};

const getInitialState = (): FormSchema => {
  const queryParams = parse(window.location.search.substring(1));

  const membershipPlans = getEnv("MEMBERSHIP_PLANS") as any[]
  const paymentMethods = getPaymentMethods();
  const getDefaultState = () => ({
    membership: membershipPlans.length ? membershipPlans[0].value : "standard",
    paymentMethod: paymentMethods.length ? paymentMethods[0] : "directDebit",
    // Default contact flags to true if not collecting consent, otherwise false
    contactByEmail: !getEnv('COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT'),
    contactByPhone: !getEnv('COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT'),
  });

  const getSavedState = () => {
    const sessionState = sessionStorage.getItem(SAVED_STATE_KEY);
    if (sessionState) {
      const state = JSON.parse(sessionState);
      const membership = state?.membership;
      const isMembershipValid = membershipPlans.filter(p => p.value === membership).length > 0
      if (!isMembershipValid) {
        state.membership = membershipPlans.length ? membershipPlans[0].value : "standard";
      }
      if (!state.customMembershipAmount) {
        delete state.customMembershipAmount
      }
      return FormSchema.cast(state, {
        strict: true
      });
    }
  };

  const getProvidedStateFromQueryParams = () => {
    if (queryParams) {
      return FormSchema.cast(queryParams, {
        strict: true
      });
    }
  };

  const state = {
    sessionToken: uuid.v4(),
    ...getTestDataIfEnabled(),
    ...getDefaultState(),
    ...getSavedState(),
    ...getProvidedStateFromQueryParams(),
    isUpdateFlow: getEnv('IS_UPDATE_FLOW'),
    webhookUuid: getEnv('WEBHOOK_UUID')
  } as any;
  return state;
};

const Fail: FC<{ router: StateRouter }> = ({ router }) => {
  useOnce(() => {
    console.error("Invalid router state", router.state);
    router.setState({ stage: "enter-details" });
  });

  return null;
};

const MinimalJoinForm = () => {
  const stripe = useStripe();
  const elements = useElements();

  const [errorMessage, setErrorMessage] = useState();
  const [otherMessage, setOtherMessage] = useState();
  const [loading, setLoading] = useState(false);

  const [selectedPlan, setSelectedPlan] = useState(null);
  const [plans, setPlans] = useState([]);
  const [email, setEmail] = useState('');

  const handleError = (error: { message: string }) => {
    setLoading(false);
    setErrorMessage(error.message);
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!stripe) {
      return;
    }

    setLoading(true);

    const { error: submitError } = await elements!.submit();

    if (submitError) {
      handleError(submitError);
      return;
    }

    const { error, confirmationToken } = await stripe!.createConfirmationToken({
      elements
    });

    // This point is only reached if there's an immediate error when
    // creating the ConfirmationToken. Show the error to your customer (for example, payment details incomplete)
    if (error) {
      handleError(error);
      return;
    }

    // Pass the confirmation token to the server to create a subscription
    const APIEndpoint = getEnv('WP_REST_API') + 'join/v1/stripe/create-confirm-subscription';

    const res = await fetch(APIEndpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        confirmationTokenId: confirmationToken.id,
        email,
        selectedPlan
      }),
    });

    const data = await res.json();

    if (data.status !== 'succeeded') {
      setErrorMessage('Connection to payment provider failed, please try again');
    }

    setOtherMessage('Payment successful, welcome');
  };

  const handleRangeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = parseInt(e.target.value);;

    setSelectedPlan(plans[value]);
  };

  useEffect(() => {
    const plans = getEnv("MEMBERSHIP_PLANS");

    const permissableMembershipPlans = plans.filter((plan) => !plan.allowCustomAmount);
    const sortedPlans = permissableMembershipPlans.sort((a, b) => a.amount - b.amount);
    const medianIndex = Math.floor(sortedPlans.length / 2);
    const medianPlan = sortedPlans[medianIndex];

    setSelectedPlan(medianPlan);
    setPlans(sortedPlans);
  }, []);

  const handleEmailChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setEmail(e.target.value);
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        {selectedPlan && `${currencyCodeToSymbol(selectedPlan.currency)}${selectedPlan.amount} ${selectedPlan.frequency}`}
      </div>
      <input
        type="range"
        min={0}
        max={plans.length - 1}
        step="1"
        onChange={handleRangeChange}
        value={plans.findIndex(plan => plan === selectedPlan)}
      />
      <div>
        <label htmlFor="email">Email</label>
        <input type="email" name="email" value={email} onChange={handleEmailChange}></input>
        <PaymentElement />
        <button type="submit" disabled={!stripe || loading}>Pay Now</button>
        {errorMessage && <div>{errorMessage}</div>}
        {otherMessage && <div>{otherMessage}</div>}
      </div>
    </form>
  );
}

export default App;
