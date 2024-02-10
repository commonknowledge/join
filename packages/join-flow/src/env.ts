interface StaticEnv {
    ASK_FOR_ADDITIONAL_DONATION: boolean;
    CHARGEBEE_API_PUBLISHABLE_KEY: string;
    CHARGEBEE_SITE_NAME: string;
    COLLECT_DATE_OF_BIRTH: boolean;
    CREATE_AUTH0_ACCOUNT: boolean;
    HOME_ADDRESS_COPY: string;
    MEMBERSHIP_PLANS: object[];
    ORGANISATION_NAME: string;
    ORGANISATION_BANK_NAME: string;
    ORGANISATION_EMAIL_ADDRESS: string;
    PASSWORD_PURPOSE: string;
    PRIVACY_COPY: string;
    POSTCODE_API_KEY: string;
    SUCCESS_REDIRECT: string;
    USE_CHARGEBEE: boolean;
    USE_GOCARDLESS: boolean;
    USE_TEST_DATA: string;
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
    COLLECT_DATE_OF_BIRTH: parseBooleanEnvVar("REACT_APP_COLLECT_DATE_OF_BIRTH"),
    CREATE_AUTH0_ACCOUNT: parseBooleanEnvVar("REACT_APP_CREATE_AUTH0_ACCOUNT"),
    HOME_ADDRESS_COPY: process.env.REACT_APP_HOME_ADDRESS_COPY || '',
    MEMBERSHIP_PLANS: JSON.parse(process.env.REACT_APP_MEMBERSHIP_PLANS || '[]') as object[],
    ORGANISATION_NAME: process.env.REACT_APP_ORGANISATION_NAME || '',
    ORGANISATION_BANK_NAME: process.env.REACT_APP_ORGANISATION_BANK_NAME || '',
    ORGANISATION_EMAIL_ADDRESS: process.env.REACT_APP_ORGANISATION_EMAIL_ADDRESS || '',
    PASSWORD_PURPOSE: process.env.REACT_APP_PASSWORD_PURPOSE || '',
    PRIVACY_COPY: process.env.REACT_APP_PRIVACY_COPY || '',
    POSTCODE_API_KEY: process.env.REACT_APP_POSTCODE_API_KEY || '',
    SUCCESS_REDIRECT: '/',
    USE_CHARGEBEE: parseBooleanEnvVar("REACT_APP_USE_CHARGEBEE"),
    USE_GOCARDLESS: parseBooleanEnvVar("REACT_APP_USE_GOCARDLESS"),
    USE_TEST_DATA: process.env.REACT_APP_POSTCODE_API_KEY || '',
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
