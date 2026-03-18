import React, { useState } from "react";
import { Button, Form } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, FormItem } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";
import { get as getEnv } from "../env";

const SUPPORTER_MONTHLY_TIERS = [3, 5, 10, 20];
const SUPPORTER_ONEOFF_TIERS = [10, 25, 50, 100];

const membershipToDonationTiers = (membership: string): Array<number> => {
  switch (membership) {
    case "suggested":
    case "standard":
      return [25, 50, 100, 500];
    case "lowWaged":
      return [4, 8];
    case "unwaged":
    case "student":
      return [3, 6];
    default:
      return [25, 50, 100, 500];
  }
};

export const DonationPage: StagerComponent<FormSchema> = ({
  data,
  onCompleted
}) => {
  const donationTiers = data.membership
    ? membershipToDonationTiers(data.membership)
    : [5, 10, 15, 20];

  const supporterMode = Boolean(getEnv("DONATION_SUPPORTER_MODE"));

  // Local state for supporter mode to drive reliable re-renders
  const [isMonthly, setIsMonthly] = useState(true);
  const [selectedTier, setSelectedTier] = useState<number>(SUPPORTER_MONTHLY_TIERS[1]);

  const form = useForm({
    defaultValues: {
      donationAmount: supporterMode ? SUPPORTER_MONTHLY_TIERS[1] : donationTiers[1],
      recurDonation: supporterMode ? true : false,
      ...data
    }
  });

  const selectedDonationAmount = form.watch("donationAmount");
  const otherDonationAmount = form.watch("otherDonationAmount");

  const handleSubmit = form.handleSubmit((formData) => {
    if (formData.otherDonationAmount !== "" && formData.otherDonationAmount != null) {
      formData.donationAmount = formData.otherDonationAmount;
      delete formData.otherDonationAmount;
    }
    onCompleted(formData);
  });

  if (supporterMode) {
    const activeTiers = isMonthly ? SUPPORTER_MONTHLY_TIERS : SUPPORTER_ONEOFF_TIERS;
    const activeAmount = (otherDonationAmount != null && otherDonationAmount !== "")
      ? Number(otherDonationAmount)
      : selectedTier;
    const ctaLabel = activeAmount > 0
      ? (isMonthly ? `Donate £${activeAmount}/month` : `Donate £${activeAmount} now`)
      : (isMonthly ? "Donate monthly" : "Donate now");

    return (
      <form className="form-content" onSubmit={handleSubmit}>
        {/* Hidden registered inputs so values appear in handleSubmit data */}
        <input type="hidden" name="donationAmount" ref={form.register} defaultValue={selectedTier} />
        <input type="hidden" name="recurDonation" ref={form.register} defaultValue={isMonthly ? "true" : "false"} />

        <div className="form-section">
          <legend className="text-md">
            <h2>Support us</h2>
          </legend>
        </div>

        <fieldset className="mt-3">
          <div className="btn-group mb-4" role="group">
            <Button
              type="button"
              variant={isMonthly ? "dark" : "outline-dark"}
              onClick={() => {
                setIsMonthly(true);
                setSelectedTier(SUPPORTER_MONTHLY_TIERS[1]);
                form.setValue("recurDonation", true);
                form.setValue("otherDonationAmount", null);
                form.setValue("donationAmount", SUPPORTER_MONTHLY_TIERS[1]);
              }}
            >
              Monthly
            </Button>
            <Button
              type="button"
              variant={!isMonthly ? "dark" : "outline-dark"}
              onClick={() => {
                setIsMonthly(false);
                setSelectedTier(SUPPORTER_ONEOFF_TIERS[1]);
                form.setValue("recurDonation", false);
                form.setValue("otherDonationAmount", null);
                form.setValue("donationAmount", SUPPORTER_ONEOFF_TIERS[1]);
              }}
            >
              One-off
            </Button>
          </div>

          <div className="mb-4">
            {activeTiers.map((tier) => (
              <Button
                key={tier}
                type="button"
                className="mr-2 mb-2"
                variant={
                  (otherDonationAmount == null || otherDonationAmount === "") &&
                  selectedTier === tier ? "dark" : "outline-dark"
                }
                onClick={() => {
                  setSelectedTier(tier);
                  form.setValue("donationAmount", tier);
                  form.setValue("otherDonationAmount", null);
                }}
              >
                £{tier}
              </Button>
            ))}
          </div>

          <FormItem
            label="Or enter another amount"
            name="otherDonationAmount"
            form={form}
            className="mt-3"
          >
            <Form.Control type="number" min="1" />
          </FormItem>
        </fieldset>

        <ContinueButton text={ctaLabel} />

        <div className="mt-2 text-center">
          <button
            type="button"
            className="btn btn-link p-0"
            onClick={() => {
              form.setValue("donationAmount", 0);
              form.handleSubmit((formData) => {
                onCompleted({ ...formData, donationAmount: 0 });
              })();
            }}
          >
            skip for now
          </button>
        </div>
      </form>
    );
  }

  return (
    <form
      className="form-content"
      onSubmit={handleSubmit}
    >
      <div>
        <Summary data={data} />
      </div>

      <div className="form-section">
        <legend className="text-md">
          <h2>Can you chip in?</h2>
        </legend>
        <p className="text-secondary">
          We rely on our members' generosity to build our movement,
          particularly as we look ahead to the next General Election and
          the work that needs to be done to gain more MPs.
        </p>
        <p className="text-secondary">
          Many of our members top up their membership, which forms a vital part of our income.
          We'd be very grateful if you would consider doing the same.
        </p>
      </div>
      <fieldset className="mt-5">
        {donationTiers.map((donationTierAmount) => (
          <Button
            className="mr-2"
            onClick={() => {
              form.setValue("donationAmount", donationTierAmount);
              form.setValue("otherDonationAmount", null);
            }}
            ref={form.register}
            variant={
              selectedDonationAmount === donationTierAmount.toString()
                ? "dark"
                : "outline-dark"
            }
            key={donationTierAmount}
            name="donationAmount"
          >
            £{donationTierAmount}
          </Button>
        ))}
        <FormItem
          label="Or enter another amount"
          name="otherDonationAmount"
          form={form}
          className="mt-5"
        >
          <Form.Control />
        </FormItem>
        <FormItem form={form} name="recurDonation">
          <Form.Check label="Make this extra donation recurring in addition to your recurring membership fee" />
        </FormItem>
      </fieldset>

      <ContinueButton text="Yes I'll chip in" />

      <ContinueButton
        onClick={() => {
          form.setValue("donationAmount", 0);
        }}
        text="Not right now"
      />
    </form>
  );
};
