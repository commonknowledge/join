// production config
const { merge } = require("webpack-merge");
const { resolve } = require("path");

const commonConfig = require("./common");

module.exports = merge(commonConfig, {
  mode: "production",
  entry: ["regenerator-runtime/runtime", "../src/index.tsx"],
  devtool: "source-map",
  plugins: []
});
