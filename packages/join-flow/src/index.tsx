/* eslint-disable */

// When we're running this as a standalone app (in development), we want to pull in the theme.
// When running in production, we defer to WordPress.
import "../scss/index.scss";

import React from "react";
import ReactDOM from "react-dom";
import * as Sentry from "@sentry/react";
import App from "./app";
import { get as getEnv, getStr as getEnvStr } from "./env";

const joinFormElement = document.querySelector(".ck-join-form");

const init = () => {
  if (!joinFormElement) {
    console.info(
      'Could not find element with class "ck-join-form" so cannot load the join form'
    );
    return;
  }


  const sentryDsn = getEnvStr("SENTRY_DSN")
  Sentry.init({
    dsn: sentryDsn,
    release: "1.3.0"
  });

  if (getEnv('USE_CHARGEBEE')) {
    if (!window.Chargebee) {
      console.error(
        "Chargebee library is not loaded in surrounding page. Chargebee React components will not function as a result. The WordPress Join Form block will include this library."
      );
      return
    }
    if (!getEnv('CHARGEBEE_SITE_NAME') || !getEnv('CHARGEBEE_API_PUBLISHABLE_KEY')) {
      console.error("Chargebee credentials are missing.");
      return
    }
    window.Chargebee.init({
      site: getEnv('CHARGEBEE_SITE_NAME'),
      publishableKey: getEnv('CHARGEBEE_API_PUBLISHABLE_KEY'),
    });
  }

  ReactDOM.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
    joinFormElement
  );
}

init()
