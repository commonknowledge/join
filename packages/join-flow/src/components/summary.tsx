import React, { FC } from "react";
import { takeWhile } from "lodash-es";
import { get as getEnv, getPaymentMethods } from "../env";
import { FormSchema, renderPaymentMethod, renderPaymentPlan } from "../schema";
import { PageState, useCurrentRouter } from "../services/router.service";
import { DetailPanel, DetailsCard } from "./atoms";

interface SummaryProps {
  data: FormSchema;
}

export const Summary: FC<SummaryProps> = ({ data }) => {
  const router = useCurrentRouter();
  const validStages = Object.keys(STAGE_COMPONENTS).filter((stage) => {
    if (stage === "donation") {
      return getEnv("ASK_FOR_ADDITIONAL_DONATION")
    }
    if (stage === "payment-method") {
      return getPaymentMethods().length > 1
    }
    return true
  })
  const stages = takeWhile(
    validStages,
    (stage) => stage !== router.state.stage
  ) as PageState["stage"][];

  return (
    <DetailsCard>
      {stages.map((stage) => {
        const StageSummary = STAGE_COMPONENTS[stage];
        return <StageSummary key={stage} data={data} />;
      })}
    </DetailsCard>
  );
};

const STAGE_COMPONENTS: Record<PageState["stage"], FC<{ data: FormSchema }>> = {
  "enter-details": ({ data }) => (
    <DetailPanel label="Email" action={{ stage: "enter-details" }}>
      {data.email}
    </DetailPanel>
  ),
  plan: ({ data }) => (
    <DetailPanel label="Plan" action={{ stage: "plan" }}>
      {renderPaymentPlan(data)}
    </DetailPanel>
  ),
  donation: ({ data }) => (
    <DetailPanel label="Donation" action={{ stage: "donation" }}>
      {data.donationAmount && data.donationAmount > 0
        ? `£${data.donationAmount} ${data.recurDonation ? "a month donation" : "one time donation"
        }`
        : "None right now"}
    </DetailPanel>
  ),
  "payment-method": ({ data }) => (
    <DetailPanel label="Billing" action={{ stage: "payment-method" }}>
      {renderPaymentMethod(data)}
    </DetailPanel>
  ),
  "payment-details": ({ data }) => {
    if (data.paymentMethod === "directDebit") {
      return (
        <>
          <DetailPanel
            action={{ stage: "payment-details" }}
            label="Account Name"
          >
            {data.ddAccountHolderName}
          </DetailPanel>
          <DetailPanel
            action={{ stage: "payment-details" }}
            label="Account Number"
          >
            {data.ddAccountNumber}
          </DetailPanel>
          <DetailPanel action={{ stage: "payment-details" }} label="Sort Code">
            {data.ddSortCode}
          </DetailPanel>
        </>
      );
    }

    return null;
  },
  confirm: () => null
};
