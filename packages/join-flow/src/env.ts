interface StaticEnv {
    CHARGEBEE_API_PUBLISHABLE_KEY: string;
    CHARGEBEE_SITE_NAME: string;
    ORGANISATION_NAME: string;
    ORGANISATION_EMAIL_ADDRESS: string;
    PASSWORD_PURPOSE: string;
    POSTCODE_API_KEY: string;
    SUCCESS_REDIRECT: string;
    USE_TEST_DATA: string;
    WP_REST_API: string;
}

const staticEnv: StaticEnv = {
    CHARGEBEE_API_PUBLISHABLE_KEY: process.env.REACT_APP_CHARGEBEE_API_PUBLISHABLE_KEY || '',
    CHARGEBEE_SITE_NAME: process.env.REACT_APP_CHARGEBEE_SITE_NAME || '',
    ORGANISATION_NAME: process.env.REACT_APP_ORGANISATION_NAME || '',
    ORGANISATION_EMAIL_ADDRESS: process.env.REACT_APP_ORGANISATION_EMAIL_ADDRESS || '',
    PASSWORD_PURPOSE: process.env.REACT_APP_PASSWORD_PURPOSE || '',
    POSTCODE_API_KEY: process.env.REACT_APP_POSTCODE_API_KEY || '',
    SUCCESS_REDIRECT: '/',
    USE_TEST_DATA: process.env.REACT_APP_POSTCODE_API_KEY || '',
    WP_REST_API: ''
}

export const get = (envVar: keyof StaticEnv): string => {
    return window.process.env[envVar] || staticEnv[envVar] || ''
}

const initFromHtml = (): void => {
    const element = document.getElementById("env");

    let env = {};

    const envJson = element?.textContent || '{}'

    try {
        env = JSON.parse(envJson);
    } catch (error) {
        console.error("Could not load environment");
    }

    console.log("Loading environment");
    window.process = Object.assign(window.process || {}, {
      env
    });
  
    console.log("Environment loaded");
    console.log(window.process.env);
    console.log(staticEnv);
}

initFromHtml()
