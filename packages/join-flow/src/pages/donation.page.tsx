import React from "react";
import { Button, Form } from "react-bootstrap";
import { useForm } from "react-hook-form";
import { ContinueButton, FormItem } from "../components/atoms";
import { StagerComponent } from "../components/stager";
import { Summary } from "../components/summary";
import { FormSchema } from "../schema";

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

  const form = useForm({
    defaultValues: {
      donationAmount: donationTiers[1],
      ...data
    }
  });

  const selectedDonationAmount = form.watch("donationAmount");

  return (
    <form
      className="form-content"
      onSubmit={form.handleSubmit((data) => {
        // From the perspective of the form schema, keep things clean with only having one variable for donation amount
        // So remove the otherDonationAmount if we have it and copy it across.
        if (data.otherDonationAmount !== "") {
          data.donationAmount = data.otherDonationAmount;
          delete data.otherDonationAmount;
        }

        onCompleted(data);
      })}
    >
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <div className="form-section">
        <legend className="text-md">Can you chip in?</legend>
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
