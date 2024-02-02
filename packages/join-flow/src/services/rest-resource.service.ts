import { get as getEnv } from '../env';

type membershipToChargebeePlanMap = {
  [key: string]: string;
};

/*
  All membership types map exactly to one Chargebee plan, except for suggested.
  
  Suggested is a combination of a membership_monthly_individual plan and a £7 donation.
  
  See packages/join-block/lib/services/join_service.php for details.
*/
const membershipToChargebeePlanMap: membershipToChargebeePlanMap = {
  suggested: "suggested",
  standard: "membership_monthly_individual",
  lowWaged: "membership_annual_individual_low_waged",
  student: "membership_annual_student",
  unwaged: "membership_annual_unwaged"
};

function membershipToPlan(membership: string): string {
  return membershipToChargebeePlanMap[membership];
}

export const usePostResource = <Params, Result = {}>(resource: string) => {
  return async (data: Params): Promise<Result> => {
    const endpoint = "join/v1" + resource;

    // @ts-ignore
    data.planId = membershipToPlan(data.membership);

    const baseUrl = getEnv('WP_REST_API').replace(/\/$/, ''); // trim trailing slash
    const res = await fetch(`${baseUrl}/${endpoint}`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        accept: "application/json"
      },
      body: JSON.stringify(data)
    });

    if (!res.ok) {
      throw Error(await res.text());
    }

    return res.json();
  };
};
