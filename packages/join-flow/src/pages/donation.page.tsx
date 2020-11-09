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
  const form = useForm({
    defaultValues: data as {}
  });

  const donationTiers = data.membership
    ? membershipToDonationTiers(data.membership)
    : [5, 10, 15, 20];

  return (
    <form className="form-content" onSubmit={form.handleSubmit(onCompleted)}>
      <div className="p-2 mt-4">
        <Summary data={data} />
      </div>

      <fieldset className="form-section">
        <legend className="text-md">Can you chip in?</legend>
        <p className="text-secondary">
          If 2,500 people give £20 each we can lay the groundwork for change
          which will lead Britain to Build Back Better, with a Just Recovery
          that uplifts everyone, especially those marginalised by our systems.
        </p>
      </fieldset>
      <fieldset>
        {donationTiers.map((donationTierAmount) => (
          <Button className="mr-2">£{donationTierAmount}</Button>
        ))}
        <FormItem
          label="Or enter another amount"
          name="otherAmount"
          form={form}
          className="mt-5"
        >
          <Form.Control />
        </FormItem>
      </fieldset>

      <ContinueButton text="Continue without donation" />
    </form>
  );
};
