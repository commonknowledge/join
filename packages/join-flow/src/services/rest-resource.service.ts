type tmembershipToChargebeePlanMap = {
  [key: string]: string;
};

const membershipToChargebeePlanMap: tmembershipToChargebeePlanMap = {
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
    const endpoint = "/join/v1" + resource;

    // @ts-ignore
    data.planId = membershipToPlan(data.membership);

    const res = await fetch("/?rest_route=" + endpoint, {
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
