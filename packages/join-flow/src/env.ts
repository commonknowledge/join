interface StaticEnv {
    CHARGEBEE_API_PUBLISHABLE_KEY: string;
    CHARGEBEE_SITE_NAME: string;
    CHARGEBEE_KEY: string;
    ORGANISATION_NAME: string;
    ORGANISATION_EMAIL_ADDRESS: string;
    POSTCODE_API_KEY: string;
    SUCCESS_REDIRECT: string;
    USE_TEST_DATA: string;
    WP_REST_API: string;
}

const staticEnv: StaticEnv = {
    CHARGEBEE_API_PUBLISHABLE_KEY: process.env.REACT_APP_CHARGEBEE_API_PUBLISHABLE_KEY || '',
    CHARGEBEE_SITE_NAME: process.env.REACT_APP_POSTCODE_API_KEY || '',
    CHARGEBEE_KEY: process.env.REACT_APP_POSTCODE_API_KEY || '',
    ORGANISATION_NAME: process.env.REACT_APP_ORGANISATION_NAME || '',
    ORGANISATION_EMAIL_ADDRESS: process.env.REACT_APP_ORGANISATION_EMAIL_ADDRESS || '',
    POSTCODE_API_KEY: process.env.REACT_APP_POSTCODE_API_KEY || '',
    SUCCESS_REDIRECT: '/',
    USE_TEST_DATA: process.env.REACT_APP_POSTCODE_API_KEY || '',
    WP_REST_API: ''
}

export const initFromHtml = (): void => {
    const element = document.getElementById("env");

    let env = {};

    if (!element) {
        return
    }

    if (!element.textContent) {
        return
    }

    try {
        env = JSON.parse(element.textContent);
    } catch (error) {
        console.error("Could not load environment");
    }

    console.log("Loading environment");
    window.process = Object.assign(window.process || {}, {
      env
    });
  
    console.log("Environment loaded");
    console.log(window.process.env);
}

export const get = (envVar: keyof StaticEnv): string => {
    return window.process.env[envVar] || staticEnv[envVar] || ''
}
