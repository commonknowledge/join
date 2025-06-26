interface StaticEnv {
    ABOUT_YOU_COPY: string;
    ABOUT_YOU_HEADING: string;
    ASK_FOR_ADDITIONAL_DONATION: boolean;
    CHARGEBEE_API_PUBLISHABLE_KEY: string;
    CHARGEBEE_SITE_NAME: string;
    COLLECT_COUNTY: boolean;
    COLLECT_DATE_OF_BIRTH: boolean;
    COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT: boolean;
    CONTACT_CONSENT_COPY: string;
    CONTACT_DETAILS_COPY: string;
    CONTACT_DETAILS_HEADING: string;
    CREATE_AUTH0_ACCOUNT: boolean;
    CUSTOM_FIELDS: object[];
    CUSTOM_FIELDS_HEADING: string;
    DATE_OF_BIRTH_COPY: string;
    DATE_OF_BIRTH_HEADING: string;
    HEAR_ABOUT_US_DETAILS: string;
    HEAR_ABOUT_US_HEADING: string;
    HEAR_ABOUT_US_OPTIONS: string[];
    HIDE_HOME_ADDRESS_COPY: boolean;
    HOME_ADDRESS_COPY: string;
    MEMBERSHIP_PLANS: object[];
    MEMBERSHIP_TIERS_HEADING: string;
    MEMBERSHIP_TIERS_COPY: string;
    ORGANISATION_NAME: string;
    ORGANISATION_BANK_NAME: string;
    ORGANISATION_EMAIL_ADDRESS: string;
    MINIMAL_JOIN_FORM: boolean;
    PASSWORD_PURPOSE: string;
    PRIVACY_COPY: string;
    STRIPE_DIRECT_DEBIT: boolean;
    STRIPE_PUBLISHABLE_KEY: string;
    SUBSCRIPTION_DAY_OF_MONTH_COPY: string;
    SUCCESS_REDIRECT: string;
    IS_UPDATE_FLOW: boolean; // email must be provided through a URL parameter
    INCLUDE_SKIP_PAYMENT_BUTTON: boolean;
    USE_CHARGEBEE: boolean;
    USE_GOCARDLESS: boolean;
    USE_GOCARDLESS_API: boolean;
    USE_MAILCHIMP: boolean;
    USE_POSTCODE_LOOKUP: boolean;
    USE_STRIPE: boolean;
    USE_TEST_DATA: boolean;
    WEBHOOK_UUID: string; // Connected to a URL in the wp_options table: `SELECT option_name FROM wp_options where option_value = :uuid`
    WP_REST_API: string;
}

const parseBooleanEnvVar = (name: string): boolean => {
    return Boolean(
        process.env[name] && process.env[name] !== "false"
    )
}

// This object holds the values that are in the .env file on disk.
// Dynamic values from the WordPress settings page are available in window.process.env.
// The get() function below checks the dynamic values first, and falls back to this object.
const staticEnv: StaticEnv = {
    ABOUT_YOU_COPY: process.env.REACT_APP_ABOUT_YOU_COPY || '',
    ABOUT_YOU_HEADING: process.env.REACT_APP_ABOUT_YOU_HEADING || '',
    ASK_FOR_ADDITIONAL_DONATION: parseBooleanEnvVar("REACT_APP_ASK_FOR_ADDITIONAL_DONATION"),
    CHARGEBEE_API_PUBLISHABLE_KEY: process.env.REACT_APP_CHARGEBEE_API_PUBLISHABLE_KEY || '',
    CHARGEBEE_SITE_NAME: process.env.REACT_APP_CHARGEBEE_SITE_NAME || '',
    COLLECT_COUNTY: parseBooleanEnvVar("REACT_APP_COLLECT_COUNTY"),
    COLLECT_DATE_OF_BIRTH: parseBooleanEnvVar("REACT_APP_COLLECT_DATE_OF_BIRTH"),
    COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT: parseBooleanEnvVar("REACT_APP_COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT"),
    CONTACT_CONSENT_COPY: process.env.REACT_ENV_CONTACT_CONSENT_COPY || '',
    CONTACT_DETAILS_COPY: process.env.REACT_APP_CONTACT_DETAILS_COPY || '',
    CONTACT_DETAILS_HEADING: process.env.REACT_APP_CONTACT_DETAILS_HEADING || '',
    CREATE_AUTH0_ACCOUNT: parseBooleanEnvVar("REACT_APP_CREATE_AUTH0_ACCOUNT"),
    CUSTOM_FIELDS: JSON.parse(process.env.REACT_APP_CUSTOM_FIELDS || '[]') as object[],
    CUSTOM_FIELDS_HEADING: process.env.REACT_APP_CUSTOM_FIELDS_HEADING || '',
    DATE_OF_BIRTH_COPY: process.env.REACT_APP_DATE_OF_BIRTH_COPY || '',
    DATE_OF_BIRTH_HEADING: process.env.REACT_APP_DATE_OF_BIRTH_HEADING || '',
    HEAR_ABOUT_US_DETAILS: process.env.REACT_APP_HEAR_ABOUT_US_DETAILS || '',
    HEAR_ABOUT_US_HEADING: process.env.REACT_APP_HEAR_ABOUT_US_HEADING || '',
    HEAR_ABOUT_US_OPTIONS: (process.env.REACT_APP_HEAR_ABOUT_US_OPTIONS || '').split('.').map(i => i.trim()).filter(Boolean),
    HIDE_HOME_ADDRESS_COPY: parseBooleanEnvVar("REACT_APP_HIDE_HOME_ADDRESS_COPY"),
    HOME_ADDRESS_COPY: process.env.REACT_APP_HOME_ADDRESS_COPY || '',
    MEMBERSHIP_PLANS: JSON.parse(process.env.REACT_APP_MEMBERSHIP_PLANS || '[]') as object[],
    MEMBERSHIP_TIERS_HEADING: process.env.REACT_APP_MEMBERSHIP_TIERS_HEADING || '',
    MEMBERSHIP_TIERS_COPY: process.env.REACT_APP_MEMBERSHIP_TIERS_COPY || '',
    MINIMAL_JOIN_FORM: parseBooleanEnvVar("REACT_APP_MINIMAL_JOIN_FORM"),
    ORGANISATION_NAME: process.env.REACT_APP_ORGANISATION_NAME || '',
    ORGANISATION_BANK_NAME: process.env.REACT_APP_ORGANISATION_BANK_NAME || '',
    ORGANISATION_EMAIL_ADDRESS: process.env.REACT_APP_ORGANISATION_EMAIL_ADDRESS || '',
    PASSWORD_PURPOSE: process.env.REACT_APP_PASSWORD_PURPOSE || '',
    PRIVACY_COPY: process.env.REACT_APP_PRIVACY_COPY || '',
    STRIPE_DIRECT_DEBIT: parseBooleanEnvVar(process.env.REACT_APP_STRIPE_DIRECT_DEBIT || ''),
    STRIPE_PUBLISHABLE_KEY: process.env.REACT_STRIPE_PUBLISHABLE_KEY || '',
    SUBSCRIPTION_DAY_OF_MONTH_COPY: process.env.REACT_APP_SUBSCRIPTION_DAY_OF_MONTH_COPY || '',
    SUCCESS_REDIRECT: '/',
    IS_UPDATE_FLOW: parseBooleanEnvVar("REACT_APP_IS_UPDATE_FLOW"),
    INCLUDE_SKIP_PAYMENT_BUTTON: parseBooleanEnvVar("REACT_APP_INCLUDE_SKIP_PAYMENT_BUTTON"),
    USE_CHARGEBEE: parseBooleanEnvVar("REACT_APP_USE_CHARGEBEE"),
    USE_GOCARDLESS: parseBooleanEnvVar("REACT_APP_USE_GOCARDLESS"),
    USE_GOCARDLESS_API: parseBooleanEnvVar("REACT_APP_USE_GOCARDLESS_API"),
    USE_MAILCHIMP: parseBooleanEnvVar("REACT_APP_USE_MAILCHIMP"),
    USE_POSTCODE_LOOKUP: parseBooleanEnvVar("REACT_APP_USE_POSTCODE_LOOKUP"),
    USE_STRIPE: parseBooleanEnvVar("REACT_APP_USE_STRIPE"),
    USE_TEST_DATA: parseBooleanEnvVar("REACT_APP_USE_TEST_DATA"),
    WEBHOOK_UUID: process.env.WEBHOOK_UUID || '',
    WP_REST_API: '',
}

export const get = (envVar: keyof StaticEnv): object[]|boolean|string => {
    return window.process.env[envVar] || staticEnv[envVar] || ''
}

export const getStr = (envVar: keyof StaticEnv): string => {
    const val = get(envVar)
    if (!val) {
        return ''
    }
    return String(val)
}

export const getPaymentMethods = () => {
    const paymentMethods = []
    // TODO: refactor paymentMethods to be ["gocardless", "chargebee", "stripe"]
    // Originally gocardless => directDebit, and chargebee => creditCard, but
    // stripe does direct debit and credit card, so this distinction is wrong.
    if (get("USE_GOCARDLESS")) {
        paymentMethods.push("directDebit")
    }
    if (get("USE_CHARGEBEE") || get("USE_STRIPE")) {
        paymentMethods.push("creditCard")
    }
    return paymentMethods
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

    window.process = Object.assign(window.process || {}, {
      env
    });
}

initFromHtml()
