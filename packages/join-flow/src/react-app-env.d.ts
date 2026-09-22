/// <reference types="@wordpress/jest-console" />

declare module "*.png" {
  const src: string;
  export default src;
}

declare module "*.svg" {
  const src: string;
  export default src;
}

interface Window {
  Chargebee: any;
}

declare module "@chargebee/chargebee-js-react-wrapper";

declare const Chargebee: any;
