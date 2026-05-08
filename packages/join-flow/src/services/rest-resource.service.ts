import { get as getEnv } from '../env';
import { formatPhoneE164 } from '../schema';

export const usePostResource = <Params, Result = {}>(resource: string) => {
  return async (data: Params): Promise<Result> => {
    const endpoint = "join/v1" + resource;

    // @ts-ignore
    data.planId = data.membership;

    // phoneCountry is a frontend-only field used to validate and format the
    // phone number. The backend expects phoneNumber in E.164 format.
    const { phoneCountry, ...rest } = data as any;
    const payload = {
      ...rest,
      ...(rest.phoneNumber
        ? { phoneNumber: formatPhoneE164(rest.phoneNumber, phoneCountry) }
        : {})
    };

    const baseUrl = getEnv('WP_REST_API').replace(/\/$/, ''); // trim trailing slash
    const res = await fetch(`${baseUrl}/${endpoint}`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        accept: "application/json"
      },
      body: JSON.stringify(payload)
    });

    if (!res.ok) {
      const body = await res.text();
      const err = new Error(body) as Error & { status: number; resource: string };
      err.status = res.status;
      err.resource = resource;
      throw err;
    }

    return res.json();
  };
};
