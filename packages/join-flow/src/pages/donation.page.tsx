import React, { useState } from "react";
import { Button, Form } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, FormItem } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema, currencyCodeToSymbol } from "../schema";
import { get as getEnv } from "../env";

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

  const membershipPlans = (getEnv("MEMBERSHIP_PLANS") as any[]) || [];
  const supporterTiers: number[] = membershipPlans.map((p) => Number(p.amount)).filter((n) => n > 0);
  const supporterCurrency: string = membershipPlans[0]?.currency || "GBP";
  const defaultSupporterTier = supporterTiers[0] ?? 0;

  // Local state for supporter mode to drive reliable re-renders
  const [isMonthly, setIsMonthly] = useState(true);
  const [selectedTier, setSelectedTier] = useState<number>(defaultSupporterTier);

  const form = useForm({
    defaultValues: {
      donationAmount: supporterMode ? defaultSupporterTier : donationTiers[1],
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

  if (supporterMode && supporterTiers.length === 0) {
    return (
      <div className="alert alert-warning m-4" role="alert">
        <strong>No donation amounts configured.</strong>
        <p className="mb-0 mt-1">
          Add membership plans to this block in the WordPress editor — their amounts will be used as the donation tiers shown to supporters.
        </p>
      </div>
    );
  }

  if (supporterMode) {
    const currencySymbol = currencyCodeToSymbol(supporterCurrency);
    const activeAmount = (otherDonationAmount != null && otherDonationAmount !== "")
      ? Number(otherDonationAmount)
      : selectedTier;
    const ctaLabel = activeAmount > 0
      ? (isMonthly ? `Donate ${currencySymbol}${activeAmount}/month` : `Donate ${currencySymbol}${activeAmount} now`)
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
                form.setValue("recurDonation", true);
              }}
            >
              Monthly
            </Button>
            <Button
              type="button"
              variant={!isMonthly ? "dark" : "outline-dark"}
              onClick={() => {
                setIsMonthly(false);
                form.setValue("recurDonation", false);
              }}
            >
              One-off
            </Button>
          </div>

          <div className="mb-4">
            {supporterTiers.map((tier) => (
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
                {currencySymbol}{tier}
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
