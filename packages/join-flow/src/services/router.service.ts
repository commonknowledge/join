import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState
} from "react";
import { snakeCase } from "lodash-es";
import { get as getEnv } from "../env";
import { FormSchema } from "../schema";

export interface PageState {
  stage:
  | "enter-details"
  | "plan"
  | "donation"
  | "payment-method"
  | "payment-details"
  | "confirm";
}

export interface StateRouter {
  state: PageState;
  setState: (state: PageState) => void;
}

/**
 * Super-simple router to allow back-navigation between form stages
 */
export const useStateRouter = (
  value: PageState,
  titles: { id: string; label: string }[]
): StateRouter => {
  const [state, setStateValue] = useState(() => window.history.state ?? value);
  useEffect(() => {
    const onPopState = (event: PopStateEvent) => {
      resetScroll();
      setTimeout(() => {
        setStateValue(event.state ?? value);
      });
    };

    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, [value]);

  useEffect(() => {
    document.title =
      titles.find((x) => x.id === state.stage)?.label ?? document.title;
  }, []);

  const setState = useCallback(
    (newState) => {
      resetScroll();
      const title =
        titles.find((x) => x.id === newState.stage)?.label ?? document.title;
      window.history.pushState(newState, title);
      document.title = title;

      setStateValue(newState);
    },
    [setStateValue]
  );

  return {
    state,
    setState
  };
};

const resetScroll = () => {
  window.scrollTo({
    left: 0,
    top: 0
  });
};

export const RouterContext = createContext<StateRouter | undefined>(undefined);

export const useCurrentRouter = () => {
  const router = useContext(RouterContext);
  if (!router) {
    throw Error("No router found!");
  }

  return router;
};

export const stripUrlParams = () => {
  window.history.replaceState(
    window.history.state,
    document.title,
    window.location.href.replace(/\?.*/, "")
  );
};

/**
 * Gets the value of key in the data object, and appends it to the URL
 * as a query parameter. The key is also converted into snake_case to be
 * more URL-ish.
 */
const addQueryParameter = (url: string, data: any, key: string) => {
  const name = snakeCase(key)
  if (data[key]) {
    if (url.includes('?')) {
      url += `&${name}=` + data[key]
    } else {
      url += `?${name}=` + data[key]
    }
  }
  return url
}

export const redirectToSuccess = (data: FormSchema) => {
  let redirectTo = getEnv('SUCCESS_REDIRECT') as string || "/"
  redirectTo = addQueryParameter(redirectTo, data, 'firstName')
  redirectTo = addQueryParameter(redirectTo, data, 'email')
  redirectTo = addQueryParameter(redirectTo, data, 'phoneNumber')
  window.location.href = redirectTo;
}
