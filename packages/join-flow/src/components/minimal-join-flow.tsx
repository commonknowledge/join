import React, { useState, useEffect } from "react";

import { PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { get as getEnv } from "../env";
import { currencyCodeToSymbol } from "../schema";

interface Plan {
    label: string;
    amount: number;
    currency: string;
    frequency: string;
    allowCustomAmount: boolean;
}

const MinimalJoinForm: React.FC = () => {
    const stripe = useStripe();
    const elements = useElements();
  
    const [errorMessage, setErrorMessage] = useState<string | undefined>();
    const [otherMessage, setOtherMessage] = useState<string | undefined>();
    const [loading, setLoading] = useState<boolean>(false);
  
    const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);
    const [plans, setPlans] = useState<Plan[]>([]);
    const [email, setEmail] = useState<string>('');
  
    const handleError = (error: { message: string }) => {
      setLoading(false);
      setErrorMessage(error.message);
    }
  
    const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
      event.preventDefault();
  
      if (!stripe || !elements) {
        return;
      }
  
      setLoading(true);
  
      const { error: submitError } = await elements.submit();
  
      if (submitError) {
        handleError(submitError);
        return;
      }
  
      const { error, confirmationToken } = await stripe.createConfirmationToken({
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
          membership: selectedPlan?.label
        }),
      });
  
      const data = await res.json();
  
      if (data.status !== 'succeeded') {
        setErrorMessage('Connection to payment provider failed, please try again');
      }
  
      setOtherMessage('Payment successful, welcome');
    };
  
    const handleRangeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
      const value = parseInt(e.target.value);
  
      setSelectedPlan(plans[value]);
    };
  
    useEffect(() => {
      const fetchedPlans = getEnv("MEMBERSHIP_PLANS") as Plan[];
  
      const permissableMembershipPlans = fetchedPlans.filter((plan) => !plan.allowCustomAmount);
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
};

export default MinimalJoinForm;
