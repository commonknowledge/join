import { useState } from "react";
import { compact, uniqueId } from "lodash-es";
import { UseFormMethods } from "react-hook-form";

import { get as getEnv } from '../env';

/**
 * Represents an Ideal Postcodes result, which has
 * the individual properties set, or a getAddress.io
 * result, which just has id and address set.
 */
class Address {
  id: string;
  address: string;
  post_town!: string;
  postcode!: string;
  line_1!: string;
  line_2!: string;
  country!: string;
  county!: string;

  /**
   * The ID will be overridden by the getAddress.io ID
   * if present. This makes it possible to tell if the
   * address came from ideal-postcodes or getAddress.io.
   */
  constructor() {
    this.id = uniqueId("idealpostcodes");
    this.address = '';
  }

  toString() {
    if (this.address) {
      return this.address
    }

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
  const [loading, setLoading] = useState<boolean>(false);

  const setPostcode = async (postcode: string) => {
    const endpoint = "join/v1/postcode";
    const baseUrl = (getEnv('WP_REST_API') as string).replace(/\/$/, ''); // trim trailing slash
    try {
      const res = await fetch(`${baseUrl}/${endpoint}?postcode=${encodeURIComponent(postcode)}`, {
        method: "GET",
        headers: {
          "content-type": "application/json",
          accept: "application/json"
        }
      });

      if (!res.ok) {
        throw Error(await res.text());
      }

      const response = await res.json();

      if (response.status !== 'ok') {
        throw new Error('Postcode address lookup failed: ' + response.status)
      }

      setAddressValue(undefined);
      setOptions(response.data.map((addr: any) => Object.assign(new Address(), addr)));
    } catch (error: any) {
      console.error(error.message)
      setOptions([]);
    }
  };

  const fetchAddress = async (id: string) => {
    const endpoint = "join/v1/address";
    const baseUrl = (getEnv('WP_REST_API') as string).replace(/\/$/, ''); // trim trailing slash
    try {
      const res = await fetch(`${baseUrl}/${endpoint}?id=${encodeURIComponent(id)}`, {
        method: "GET",
        headers: {
          "content-type": "application/json",
          accept: "application/json"
        }
      });

      if (!res.ok) {
        throw Error(await res.text());
      }

      const response = await res.json();

      if (response.status !== 'ok') {
        throw new Error('Postcode address lookup failed: ' + response.status)
      }

      return response.data
    } catch (error: any) {
      console.error(error.message)
    }
    return {}
  }

  const setAddress = async (id: string) => {
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

    let address: Address | null = null;

    // ideal-postcodes address lookups have all the required data
    if (id.startsWith('idealpostcodes')) {
      address = hit
    }
    // however getAddress.io lookups require another request to populate
    // the address components
    else {
      setLoading(true)
      address = await fetchAddress(id);
    }

    setTimeout(() => {
      if (address !== null) {
        setFormValue("addressLine1", address.line_1);
        setFormValue("addressLine2", address.line_2);
        setFormValue("addressCity", address.post_town);
        setFormValue("addressCounty", address.county);
        setFormValue("addressPostcode", address.postcode);
        setFormValue("addressCountry", "GB");
      }
      setLoading(false)
    });
  };

  return {
    setPostcode,
    setAddress,
    address,
    options,
    loading
  };
};
