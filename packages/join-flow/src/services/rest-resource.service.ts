import { get as getEnv } from '../env';

export const usePostResource = <Params, Result = {}>(resource: string) => {
  return async (data: Params): Promise<Result> => {
    const endpoint = "join/v1" + resource;

    // @ts-ignore
    data.planId = data.membership;

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
