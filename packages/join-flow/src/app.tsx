import { parse } from "querystring";

import * as uuid from "uuid";
import React, { FC, useCallback, useState } from "react";
import { TransitionGroup, CSSTransition } from "react-transition-group";
import { Container } from "react-bootstrap";

import { DetailsPage } from "./pages/details.page";
import { PaymentPage } from "./pages/payment-method.page";
import { PlanPage } from "./pages/plan.page";
import {
  RouterContext,
  StateRouter,
  stripUrlParams,
  useStateRouter
} from "./services/router.service";
import { useOnce } from "./hooks/util";
import { PaymentDetailsPage } from "./pages/paymend-details.page";
import { Stager } from "./components/stager";
import { FormSchema, getTestDataIfEnabled } from "./schema";
import { ConfirmationPage } from "./pages/confirm.page";
import { usePostResource } from "./services/rest-resource.service";

const stages = [
  { id: "enter-details", label: "Your Details", breadcrumb: true },
  { id: "plan", label: "Your Membership", breadcrumb: true },
  { id: "payment-details", label: "Payment", breadcrumb: true },
  { id: "payment-method", label: "Payment", breadcrumb: false },
  { id: "confirm", label: "Confirm", breadcrumb: false }
];

const SAVED_STATE_KEY = "greens_join_state_flow";

const App = () => {
  const join = usePostResource<FormSchema>("/join");
  const [data, setData] = useState(getInitialState);

  const router = useStateRouter({
    stage: "enter-details"
  });

  useOnce(stripUrlParams);

  const currentIndex = stages.findIndex((x) => x.id === router.state.stage);
  const handlePageCompleted = useCallback(
    (change: FormSchema) => {
      const nextData = {
        ...data,
        ...change
      } as FormSchema;

      setData(nextData);
      sessionStorage.setItem(SAVED_STATE_KEY, JSON.stringify(nextData));

      if (router.state.stage === "enter-details") {
        router.setState({ stage: "plan" });
      } else if (router.state.stage === "plan") {
        router.setState({ stage: "payment-method" });
      } else if (router.state.stage === "payment-method") {
        router.setState({ stage: "payment-details" });
      } else if (router.state.stage === "payment-details") {
        router.setState({ stage: "confirm" });
      } else if (router.state.stage === "confirm") {
        join(data).then((res) => {
          window.location.href = "/new-joiner";
        });
      }
    },
    [router, join, data]
  );

  return (
    <RouterContext.Provider value={router}>
      <div className="form-content">
        <div className="progress-steps px-2 w-100">
          {stages.map(
            (stage, i) =>
              stage.breadcrumb && (
                <span
                  key={stage.id}
                  className={`progress-text ${
                    i > currentIndex ? "text-muted" : ""
                  }`}
                >
                  {stage.label}
                </span>
              )
          )}
        </div>
      </div>

      <TransitionGroup component={null}>
        <CSSTransition
          key={router.state.stage}
          classNames="progress-stage-content"
          timeout={300}
        >
          <Stager
            stage={router.state.stage}
            data={data}
            onStageCompleted={handlePageCompleted}
            components={{
              "enter-details": DetailsPage,
              plan: PlanPage,
              "payment-details": PaymentDetailsPage,
              "payment-method": PaymentPage,
              confirm: ConfirmationPage
            }}
            fallback={<Fail router={router} />}
          />
        </CSSTransition>
      </TransitionGroup>
    </RouterContext.Provider>
  );
};

const getInitialState = (): FormSchema => {
  const queryParams = parse(window.location.search.substr(1));

  const getDefaultState = () => ({
    membership: "suggested",
    paymentMethod: "directDebit"
  });

  const getSavedState = () => {
    const sesisonState = sessionStorage.getItem(SAVED_STATE_KEY);
    if (sesisonState) {
      return FormSchema.cast(JSON.parse(sesisonState), {
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
    ...getDefaultState(),
    ...getTestDataIfEnabled(),
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
