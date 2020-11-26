import { camelCase, memoize } from "lodash-es";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

export const useOnce = (fn: () => void) => useEffect(fn, []);
export const useMemoOnce = <T>(fn: () => T) => useMemo(fn, []);

export type KeySet = Record<string, boolean>;

export const useSet = (initialValues: KeySet | (() => KeySet)): any => {
  const [state, setState] = useState(initialValues);
  const stateRef = useRef(state);
  stateRef.current = state;

  const setKey = useCallback(
    (key: string, value: boolean) => {
      setState({
        ...stateRef.current,
        [key]: value
      });
    },
    [stateRef, setState]
  );

  return [state, setKey];
};

export const useAsync = <T>(fn: () => Promise<T>): T | undefined => {
  const [state, setState] = useState<T>();

  useOnce(() => {
    fn().then((value) => setState(value));
  });

  return state;
};

// Creates a invisible element and adds it to the DOM
const getHiddenWrapperElement = memoize(() => {
  const el = document.createElement("div");
  el.style.opacity = "0";
  el.style.position = "absolute";
  document.body.appendChild(el);

  return el;
});

// Trick for getting an iframed control to inherit the parent window's CSS
export const useCSSStyle = (
  className: string,
  type = "div",
  propsList?: string[]
) => {
  return useMemo(() => {
    // Create an element on the page and apply the style to it
    const el = document.createElement(type);
    el.className = className;

    // Append element invisibly to the DOM
    getHiddenWrapperElement().appendChild(el);

    // Query the styles of this element
    const styles = window.getComputedStyle(el);
    const res: any = {};

    // Translate styles into a styles object that can be understood by React
    Array.from(styles).forEach((key) => {
      const value = styles.getPropertyValue(key);
      if (value) {
        const camelKey = camelCase(key);

        if (!propsList || propsList.includes(camelKey)) {
          res[camelKey] = value;
        }
      }
    });

    return res;
  }, [className, type, propsList]);
};
