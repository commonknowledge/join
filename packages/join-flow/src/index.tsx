/* eslint-disable */

// When we're running this as a standalone app (in development), we want to pull in the theme.
// When running in production, we defer to WordPress.
const { USE_BUNDLED_THEME } = window as any;

if (process.env.NODE_ENV !== "production" && USE_BUNDLED_THEME) {
  require("uk-greens-theme/scss/index.scss");
}

import React from "react";
import ReactDOM from "react-dom";
import App from "./app";

const joinFormElement = document.getElementById("join-form");

function getEnvironmentFromHTML() {
  const element = document.getElementById("env");

  if (!element) {
    return {};
  }

  if (!element.textContent) {
    return {};
  }

  const env = JSON.parse(element.textContent);

  return env;
}

if (!joinFormElement) {
  console.error(
    'Could not find element with ID "join-form" so cannot load Green Party join form'
  );
} else {
  console.log("Loading environment");
  window.process = Object.assign(window.process || {}, {
    env: getEnvironmentFromHTML()
  });

  if (window.Chargebee) {
    window.Chargebee.init({
      site:
        window.process.env.CHARGEBEE_SITE_NAME ||
        process.env.REACT_APP_CHARGEBEE_SITE,
      publishableKey:
        window.process.env.CHARGEBEE_PUBLISHABLE_KEY ||
        process.env.REACT_APP_CHARGEBEE_KEY
    });
  } else {
    console.error(
      "Chargebee library is not loaded in surrounding page. Chargebee React components will not function as a result.\n\nWhen the Green Party join form is loaded in WordPress, this should be loaded when the Join Form block is present on the page."
    );
  }

  ReactDOM.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
    document.getElementById("join-form")
  );
}
