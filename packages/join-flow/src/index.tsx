/* eslint-disable */

// When we're running this as a standalone app (in development), we want to pull in the theme.
// When running in production, we defer to wordpress.
if (process.env.NODE_ENV !== 'production') {
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
