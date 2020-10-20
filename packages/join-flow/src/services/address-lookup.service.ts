import { Client } from "@ideal-postcodes/core-browser";
import { useState } from "react";
import { compact, uniqueId } from "lodash-es";
import { UseFormMethods } from "react-hook-form";

const client = new Client({ api_key: process.env.REACT_APP_POSTCODE_API_KEY! });
const useTestAddress =
	process.env.NODE_ENV !== "production" &&
	!process.env.REACT_APP_PRODUCTION_ADDRESS_LOOKUP;

class Address {
	id: string;
	post_town!: string;
	postcode!: string;
	line_1!: string;
	line_2!: string;
	country!: string;
	county!: string;

	constructor() {
		this.id = uniqueId("address");
	}

	toString() {
		return compact([
			this.line_1,
			this.line_2,
			this.postcode,
			this.county,
			this.country
		]).join(", ");
	}
}

export const useAddressLookup = (form: UseFormMethods<any>) => {
	const [options, setOptions] = useState<Address[]>();
	const [address, setAddressValue] = useState<Address>();

	const setPostcode = async (postcode: string) => {
		try {
			const addresses = await client.lookupPostcode({
				postcode: useTestAddress ? "ID1 1QD" : postcode
			});

			setAddressValue(undefined);
			setOptions(addresses.map((addr) => Object.assign(new Address(), addr)));
		} catch (error) {
			if (error instanceof Client.errors.IdpcRequestFailedError) {
				setOptions([]);
			}
		}
	};

	const setAddress = (id: string) => {
		const hit = options?.find((x) => x.id === id);
		if (!hit) {
			return;
		}

		setAddressValue(hit);

		const setFormValue = (name: string, value: string) => {
			// Hacky workaround for react-hook-form not being fully reactive
			const el =
				document.querySelector<HTMLInputElement>(`input[name="${name}`) ??
				document.querySelector<HTMLInputElement>(`select[name="${name}"`);
			if (!el) {
				return;
			}

			el.value = value;
			form.setValue(name, value, {
				shouldDirty: true,
				shouldValidate: true
			});
		};

		setTimeout(() => {
			setFormValue("addressLine1", hit.line_1);
			setFormValue("addressLine2", hit.line_2);
			setFormValue("addressCity", hit.post_town);
			setFormValue("addressCounty", hit.county);
			setFormValue("addressPostcode", hit.postcode);
			setFormValue("addressCountry", "GB");
		});
	};

	return {
		setPostcode,
		setAddress,
		address,
		options
	};
};
