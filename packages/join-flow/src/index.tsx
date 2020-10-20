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

if (!joinFormElement) {
  console.error(
    'Could not find element with ID "join-form" so cannot load Green Party join form'
  );
} else {
  ReactDOM.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
    document.getElementById("join-form")
  );
}
