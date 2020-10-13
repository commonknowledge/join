/* eslint-disable */

// When we're running this as a standalone app (in development), we want to pull in the theme.
// When running in production, we defer to wordpress.
const {USE_BUNDLED_THEME} = window as any

console.log(process.env.REACT_APP_PRODUCTION_ADDRESS_LOOKUP)

if (process.env.NODE_ENV !== 'production' && USE_BUNDLED_THEME) {
  require('uk-greens-theme/scss/index.scss')
}

import React from 'react';
import ReactDOM from 'react-dom';
import App from './app';

ReactDOM.render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
  document.getElementById('join-form')
);
