import isoCountries from "iso-3166";

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