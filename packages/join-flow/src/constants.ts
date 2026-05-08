import isoCountries from "iso-3166";
import { PhoneNumberUtil } from "google-libphonenumber";

export const sortedCountries = isoCountries.sort((a, b) => {
    // Prioritize The United Kingdom
    if (a.alpha2 === "GB") {
        return -1
    }
    if (b.alpha2 === "GB") {
        return 1
    }
    return a.name < b.name ? -1 : 1
})

const phoneUtil = PhoneNumberUtil.getInstance();

export interface PhoneCountry {
    alpha2: string;
    name: string;
    dialingCode: number;
}

export const phoneCountries: PhoneCountry[] = sortedCountries
    .map((c) => ({
        alpha2: c.alpha2,
        name: c.name,
        dialingCode: phoneUtil.getCountryCodeForRegion(c.alpha2)
    }))
    .filter((c) => c.dialingCode > 0);