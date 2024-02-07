/* eslint-disable */

// When we're running this as a standalone app (in development), we want to pull in the theme.
// When running in production, we defer to WordPress.
import "../scss/index.scss";

import React from "react";
import ReactDOM from "react-dom";
import App from "./app";
import { get as getEnv } from "./env";

const joinFormElement = document.querySelector(".ck-join-form");

if (!joinFormElement) {
  console.error(
    'Could not find element with class "ck-join-form" so cannot load the join form'
  );
} else {
  if (window.Chargebee) {
    window.Chargebee.init({
      site: getEnv('CHARGEBEE_SITE_NAME'),
      publishableKey: getEnv('CHARGEBEE_API_PUBLISHABLE_KEY'),
    });
  } else {
    console.error(
      "Chargebee library is not loaded in surrounding page. Chargebee React components will not function as a result. The WordPress Join Form block will include this library."
    );
  }

  ReactDOM.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
    joinFormElement
  );
}
