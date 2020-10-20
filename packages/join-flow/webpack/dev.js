// development config
const { merge } = require("webpack-merge");
const webpack = require("webpack");
const commonConfig = require("./common");
const path = require("path");

module.exports = merge(commonConfig, {
  mode: "development",
  entry: [
    "regenerator-runtime/runtime",
    "react-hot-loader/patch", // activate HMR for React
    "webpack-dev-server/client?http://localhost:3000", // bundle the client for webpack-dev-server and connect to the provided endpoint
    "webpack/hot/only-dev-server", // bundle the client for hot reloading, only- means to only hot reload for successful updates
    "../src/index.tsx" // the entry point of our app
  ],
  devServer: {
    hot: true, // enable HMR on the server
    port: 3000,
    publicPath: "/",
    contentBase: path.join(__dirname, "dist")
  },
  devtool: "cheap-module-source-map",
  plugins: [
    new webpack.HotModuleReplacementPlugin() // enable HMR globally
  ]
});
