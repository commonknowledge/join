interface StaticEnv {
    ASK_FOR_ADDITIONAL_DONATION: boolean;
    CHARGEBEE_API_PUBLISHABLE_KEY: string;
    CHARGEBEE_SITE_NAME: string;
    COLLECT_COUNTY: boolean;
    COLLECT_DATE_OF_BIRTH: boolean;
    COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT: boolean;
    CREATE_AUTH0_ACCOUNT: boolean;
    HOME_ADDRESS_COPY: string;
    MEMBERSHIP_PLANS: object[];
    ORGANISATION_NAME: string;
    ORGANISATION_BANK_NAME: string;
    ORGANISATION_EMAIL_ADDRESS: string;
    PASSWORD_PURPOSE: string;
    PRIVACY_COPY: string;
    SUCCESS_REDIRECT: string;
    IS_UPDATE_FLOW: boolean; // email must be provided through a URL parameter
    USE_CHARGEBEE: boolean;
    USE_GOCARDLESS: boolean;
    USE_GOCARDLESS_API: boolean;
    USE_POSTCODE_LOOKUP: boolean;
    USE_TEST_DATA: boolean;
    WEBHOOK_UUID: string; // Connected to a URL in the wp_options table: `SELECT option_name FROM wp_options where option_value = :uuid`
    WP_REST_API: string;
}

const parseBooleanEnvVar = (name: string): boolean => {
    return Boolean(
        process.env[`REACT_APP_${name}`] && process.env[`REACT_APP_${name}`] !== "false"
    )
}

const staticEnv: StaticEnv = {
    ASK_FOR_ADDITIONAL_DONATION: parseBooleanEnvVar("REACT_APP_ASK_FOR_ADDITIONAL_DONATION"),
    CHARGEBEE_API_PUBLISHABLE_KEY: process.env.REACT_APP_CHARGEBEE_API_PUBLISHABLE_KEY || '',
    CHARGEBEE_SITE_NAME: process.env.REACT_APP_CHARGEBEE_SITE_NAME || '',
    COLLECT_COUNTY: parseBooleanEnvVar("REACT_APP_COLLECT_COUNTY"),
    COLLECT_DATE_OF_BIRTH: parseBooleanEnvVar("REACT_APP_COLLECT_DATE_OF_BIRTH"),
    COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT: parseBooleanEnvVar("REACT_APP_COLLECT_PHONE_AND_EMAIL_CONTACT_CONSENT"),
    CREATE_AUTH0_ACCOUNT: parseBooleanEnvVar("REACT_APP_CREATE_AUTH0_ACCOUNT"),
    HOME_ADDRESS_COPY: process.env.REACT_APP_HOME_ADDRESS_COPY || '',
    MEMBERSHIP_PLANS: JSON.parse(process.env.REACT_APP_MEMBERSHIP_PLANS || '[]') as object[],
    ORGANISATION_NAME: process.env.REACT_APP_ORGANISATION_NAME || '',
    ORGANISATION_BANK_NAME: process.env.REACT_APP_ORGANISATION_BANK_NAME || '',
    ORGANISATION_EMAIL_ADDRESS: process.env.REACT_APP_ORGANISATION_EMAIL_ADDRESS || '',
    PASSWORD_PURPOSE: process.env.REACT_APP_PASSWORD_PURPOSE || '',
    PRIVACY_COPY: process.env.REACT_APP_PRIVACY_COPY || '',
    SUCCESS_REDIRECT: '/',
    IS_UPDATE_FLOW: parseBooleanEnvVar("REACT_APP_IS_UPDATE_FLOW"),
    USE_CHARGEBEE: parseBooleanEnvVar("REACT_APP_USE_CHARGEBEE"),
    USE_GOCARDLESS: parseBooleanEnvVar("REACT_APP_USE_GOCARDLESS"),
    USE_GOCARDLESS_API: parseBooleanEnvVar("REACT_APP_USE_GOCARDLESS_API"),
    USE_POSTCODE_LOOKUP: parseBooleanEnvVar("REACT_APP_USE_POSTCODE_LOOKUP"),
    USE_TEST_DATA: parseBooleanEnvVar("REACT_APP_USE_TEST_DATA"),
    WEBHOOK_UUID: process.env.WEBHOOK_UUID || '',
    WP_REST_API: ''
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
    if (get("USE_GOCARDLESS")) {
        paymentMethods.push("directDebit")
    }
    if (get("USE_CHARGEBEE")) {
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
