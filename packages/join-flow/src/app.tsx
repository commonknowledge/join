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
import { FormSchema, getPaymentPlan, getTestDataIfEnabled } from "./schema";
import { ConfirmationPage } from "./pages/confirm.page";
import { get as getEnv, getStr as getEnvStr, getPaymentMethods } from "./env";
import { usePostResource } from "./services/rest-resource.service";
import gocardless from "./images/gocardless.svg";
import chargebee from "./images/chargebee.png";
import stripe from "./images/stripe.png";

import { Elements } from "@stripe/react-stripe-js";
import MinimalJoinForm from "./components/minimal-join-flow";
import { loadStripe } from "@stripe/stripe-js";

interface Stage {
  id: PageState["stage"];
  label: string;
  breadcrumb: boolean;
}

// Redirect to confirm page if ?gocardless_success === "true"
// because that is a redirect from GoCardless
// Also require a billing request ID to be present.
const searchParams = new URLSearchParams(window.location.search);
const cbRedirect = searchParams.get("chargebee_success") === "true";
const gcRedirect = searchParams.get("gocardless_success") === "true";
const stripeRedirect = searchParams.get("stripe_success") === "true";
const savedSession = JSON.parse(
  sessionStorage.getItem(SAVED_STATE_KEY) || "{}"
);
const cbHostedPageId = savedSession["cbHostedPageId"];
const gcBillingRequestId = savedSession["gcBillingRequestId"];
const stripePaymentIntentId = savedSession["stripePaymentIntentId"];
let shouldRedirectToConfirm =
  (gcRedirect && gcBillingRequestId) ||
  (stripeRedirect && stripePaymentIntentId) ||
  (cbRedirect && cbHostedPageId);

// @ts-ignore
const stripePromise = loadStripe(getEnv("STRIPE_PUBLISHABLE_KEY"));

const App = () => {
  let stages: Stage[] = [
    { id: "enter-details", label: "Your Details", breadcrumb: true },
    { id: "plan", label: getEnvStr("MEMBERSHIP_STAGE_LABEL"), breadcrumb: true },
    { id: "donation", label: "Can you chip in?", breadcrumb: false },
    { id: "payment-details", label: "Payment", breadcrumb: true },
    { id: "payment-method", label: "Payment", breadcrumb: false },
    { id: "confirm", label: "Confirm", breadcrumb: false }
  ];

  if (getEnv("IS_UPDATE_FLOW")) {
    stages = stages.filter((s) => s.id !== "enter-details");
  }
  const [data, setData] = useState(getInitialState);
  const [blockingMessage, setBlockingMessage] = useState<string | null>(null);

  const router = useStateRouter(
    {
      stage: stages[0].id
    },
    stages
  );

  useEffect(
    function routerAnalytics() {
      try {
        // @ts-ignore
        // In case there is a posthog install in the parent website
        posthog?.capture("join flow navigation", { stage: router.state.stage });
      } catch (e) {}
    },
    [router]
  );

  if (shouldRedirectToConfirm) {
    router.setState({ stage: "confirm" });
    // Prevent infinite loop
    shouldRedirectToConfirm = false;
  }

  useOnce(stripUrlParams);

  const recordStep =
    usePostResource<Partial<FormSchema & { stage: string }>>("/step");
  const getGoCardlessRedirect =
    usePostResource<Partial<FormSchema & { redirectUrl: string }>>(
      "/gocardless/auth"
    );
  const getChargeBeeRedirect =
    usePostResource<Partial<FormSchema & { redirectUrl: string }>>(
      "/chargebee/hosted-page"
    );

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

      let nextStage = router.state.stage;

      if (router.state.stage === "enter-details") {
        nextStage = "plan";
        // Send initial details to catch drop off
        const response: any = await recordStep({ ...nextData, stage: "enter-details" });
        
        // Check if the form progression is blocked
        if (response?.status === 'blocked') {
          setBlockingMessage(response.message || 'Unable to proceed with this submission.');
          return; // Stop progression
        }
        
        // Clear any previous blocking message
        setBlockingMessage(null);
      } else if (router.state.stage === "plan") {
        nextStage = "donation";
      } else if (router.state.stage === "donation") {
        nextStage = "payment-method";
      } else if (router.state.stage === "payment-method") {
        // Check if this is a zero-price membership before setting next stage
        // This handles the case where the user is ON the payment-method page
        // (e.g., when there are multiple payment methods to choose from)
        const plan = getPaymentPlan(nextData.membership);
        const isAmountZero = plan && Number(plan.amount) === 0;
        const isCustomAmountAboveZero = (plan && plan.allowCustomAmount) ? Number(nextData.customMembershipAmount) > 0 : false;
        const hasDonation = nextData.donationAmount && Number(nextData.donationAmount) > 0;
        
        // Skip payment only if membership is free, no custom amount, AND no donation
        if (isAmountZero && !isCustomAmountAboveZero && !hasDonation) {
          nextStage = "confirm";
        } else {
          nextStage = "payment-details";
        }
      } else if (router.state.stage === "payment-details") {
        nextStage = "confirm";
      } else if (router.state.stage === "confirm") {
        router.reset();
        await redirectToSuccess(data);
      }

      if (nextStage === "donation" && !includeDonationPage) {
        nextStage = "payment-method";
      }

      // Skip payment entirely if member has selected a free membership when arriving
      // at the payment-method stage from a previous stage (e.g., plan or donation).
      // Note: There's a similar check above for when the user is already ON the
      // payment-method page - both are needed to cover all navigation paths.
      const plan = getPaymentPlan(nextData.membership);
      const isAmountZero = plan && Number(plan.amount) === 0;
      const isCustomAmountAboveZero = (plan && plan.allowCustomAmount) ? Number(nextData.customMembershipAmount) > 0 : false;
      const hasDonation = nextData.donationAmount && Number(nextData.donationAmount) > 0;
      
      // Only skip payment if there's truly nothing to pay (no membership fee, no custom amount, no donation)
      const shouldSkipPayment = nextStage === "payment-method" && isAmountZero && !isCustomAmountAboveZero && !hasDonation;

      if (shouldSkipPayment) {
        // For zero-price memberships with no donation, skip directly to confirmation
        nextStage = "confirm";
      } else if (nextStage === "payment-method" && getPaymentMethods().length < 2) {
        // Auto-skip payment method selection if there's only one option available
        // This only runs if we haven't already skipped payment entirely above
        nextStage = "payment-details";
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
          JSON.stringify({
            ...nextData,
            gcBillingRequestId: goCardlessHrefResponse.gcBillingRequestId
          })
        );
        window.location.href = goCardlessHrefResponse.href;
      }

      // Go to external ChargeBee pages if not using the ChargeBee API
      if (
        nextStage === "payment-details" &&
        nextData.paymentMethod === "creditCard" &&
        getEnv("USE_CHARGEBEE") &&
        getEnv("USE_CHARGEBEE_HOSTED_PAGES")
      ) {
        // Undo the transition to prevent flicker
        nextStage = router.state.stage;
        // Redirect to ChargeBee
        const redirectUrl = encodeURI(window.location.href);

        const chargeBeeRedirectResponse: any = await getChargeBeeRedirect({
          ...nextData,
          redirectUrl
        });
        sessionStorage.setItem(
          SAVED_STATE_KEY,
          JSON.stringify({
            ...nextData,
            cbHostedPageId: chargeBeeRedirectResponse.cbHostedPageId
          })
        );
        window.location.href = chargeBeeRedirectResponse.href;
      }

      router.setState({ stage: nextStage });
    },
    [router, data, setBlockingMessage]
  );

  const paymentProviderLogos = getPaymentMethods().map((method) => {
    return getEnv("USE_GOCARDLESS") ? (
      <a key={method} href="https://gocardless.com" target="_blank">
        <img alt="GoCardless" src={gocardless} width="100px" />
      </a>
    ) : getEnv("USE_CHARGEBEE") ? (
      <a key={method} href="https://chargebee.com" target="_blank">
        <img alt="Chargebee" src={chargebee} width="100px" />
      </a>
    ) : getEnv("USE_STRIPE") ? (
      <a key={method} href="https://stripe.com" target="_blank">
        <img alt="Stripe" src={stripe} width="100px" />
      </a>
    ) : null;
  });

  const options = {
    paymentMethodCreation: "manual",
    mode: "subscription",
    amount: 100,
    currency: "gbp"
  };

  // @ts-ignore
  const minimalJoinForm = (
    <Elements stripe={stripePromise} options={options}>
      <MinimalJoinForm />
    </Elements>
  );

  const fullJoinForm = (
    <>
      <RouterContext.Provider value={router}>
        <div className="progress-steps">
          <h6>{getEnvStr("JOIN_FORM_SIDEBAR_HEADING")}</h6>
          <div className="progress-steps__secure">
            <p>
              🔒 Secure payment with
              <br />
              {paymentProviderLogos}
            </p>
          </div>
          <ul className="p-0 list-unstyled">
            {stages.map(
              (stage, i) =>
                stage.breadcrumb && (
                  <li
                    key={stage.id}
                    className={`progress-step progress-step--${i < currentIndex ? "done" : i === currentIndex ? "current" : "next"}`}
                  >
                    <div>
                      <span className="progress-circle"></span>
                      <span className="progress-text">{stage.label}</span>
                    </div>
                    <div className="progress-line"></div>
                  </li>
                )
            )}
          </ul>
        </div>

        {blockingMessage ? (
          <div className="ml-4">
            <div className="alert alert-danger" role="alert">
              <div dangerouslySetInnerHTML={{ __html: blockingMessage }} />
            </div>
          </div>
        ) : (
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
        )}
      </RouterContext.Provider>
    </>
  );

  let form = fullJoinForm;

  if (getEnv("MINIMAL_JOIN_FORM")) {
    form = minimalJoinForm;
  }

  return form;
};

const getInitialState = (): FormSchema => {
  const queryParams = parse(window.location.search.substring(1));

  const membershipPlans = getEnv("MEMBERSHIP_PLANS") as any[];
  const paymentMethods = getPaymentMethods();
  const getDefaultState = () => ({
    membership: membershipPlans.length ? membershipPlans[0].value : "standard",
    paymentMethod: paymentMethods.length ? paymentMethods[0] : "creditCard",
    // Default contact flags to true if not collecting consent, otherwise false
    contactByEmail: !getEnv("COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT"),
    contactByPhone: !getEnv("COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT")
  });

  const getSavedState = () => {
    const sessionState = sessionStorage.getItem(SAVED_STATE_KEY);
    if (sessionState) {
      const state = JSON.parse(sessionState);
      const membership = state?.membership;
      const isMembershipValid =
        membershipPlans.filter((p) => p.value === membership).length > 0;
      if (!isMembershipValid) {
        state.membership = membershipPlans.length
          ? membershipPlans[0].value
          : "standard";
      }
      if (!state.customMembershipAmount) {
        delete state.customMembershipAmount;
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
    isUpdateFlow: getEnv("IS_UPDATE_FLOW"),
    webhookUuid: getEnv("WEBHOOK_UUID"),
    customFieldsConfig: getEnv("CUSTOM_FIELDS")
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

export default App;
