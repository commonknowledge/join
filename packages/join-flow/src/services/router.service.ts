import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState
} from "react";

export interface PageState {
  stage:
    | "enter-details"
    | "plan"
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
