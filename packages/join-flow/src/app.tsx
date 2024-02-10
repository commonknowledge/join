import { parse } from "querystring";

import * as uuid from "uuid";
import React, { FC, useCallback, useState } from "react";

import { DetailsPage } from "./pages/details.page";
import { PaymentPage } from "./pages/payment-method.page";
import { PlanPage } from "./pages/plan.page";
import { DonationPage } from "./pages/donation.page";
import {
  RouterContext,
  StateRouter,
  stripUrlParams,
  useStateRouter
} from "./services/router.service";
import { useOnce } from "./hooks/util";
import { PaymentDetailsPage } from "./pages/payment-details.page";
import { Stager } from "./components/stager";
import { FormSchema, getTestDataIfEnabled } from "./schema";
import { ConfirmationPage } from "./pages/confirm.page";
import { get as getEnv, getPaymentMethods } from "./env";

const stages = [
  { id: "enter-details", label: "Your Details", breadcrumb: true },
  { id: "plan", label: "Your Membership", breadcrumb: true },
  { id: "donation", label: "Can you chip in?", breadcrumb: false },
  { id: "payment-details", label: "Payment", breadcrumb: true },
  { id: "payment-method", label: "Payment", breadcrumb: false },
  { id: "confirm", label: "Confirm", breadcrumb: false }
];

const SAVED_STATE_KEY = "ck_join_state_flow";

const App = () => {
  const [data, setData] = useState(getInitialState);

  const router = useStateRouter(
    {
      stage: "enter-details"
    },
    stages
  );

  useOnce(stripUrlParams);

  const currentIndex = stages.findIndex((x) => x.id === router.state.stage);
  const handlePageCompleted = useCallback(
    (change: FormSchema) => {
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
      } else if (router.state.stage === "plan") {
        nextStage = "donation"
      } else if (router.state.stage === "donation") {
        nextStage = "payment-method"
      } else if (router.state.stage === "payment-method") {
        nextStage = "payment-details"
      } else if (router.state.stage === "payment-details") {
        nextStage = "confirm"
      } else if (router.state.stage === "confirm") {
        window.location.href = getEnv('SUCCESS_REDIRECT') as string || "/";
      }

      if (nextStage === "donation" && !includeDonationPage) {
        nextStage = "payment-method"
      }

      if (nextStage === "payment-method" && getPaymentMethods().length < 2) {
        nextStage = "payment-details"
      }

      router.setState({ stage: nextStage });
    },
    [router, data]
  );

  return (
    <RouterContext.Provider value={router}>
      <div className="progress-steps">
        <h6>Join Us</h6>
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
  );
};

const getInitialState = (): FormSchema => {
  const queryParams = parse(window.location.search.substring(1));

  const membershipPlans = getEnv("MEMBERSHIP_PLANS") as any[]
  const paymentMethods = getPaymentMethods();
  const getDefaultState = () => ({
    membership: membershipPlans.length ? membershipPlans[0].value : "standard",
    paymentMethod: paymentMethods.length ? paymentMethods[0] : "directDebit"
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

  return {
    sessionToken: uuid.v4(),
    ...getTestDataIfEnabled(),
    ...getDefaultState(),
    ...getSavedState(),
    ...getProvidedStateFromQueryParams()
  } as any;
};

const Fail: FC<{ router: StateRouter }> = ({ router }) => {
  useOnce(() => {
    console.error("Invalid router state", router.state);
    router.setState({ stage: "enter-details" });
  });

  return null;
};

export default App;
