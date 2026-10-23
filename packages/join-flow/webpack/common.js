// shared config (dev and prod)
const { resolve } = require("path");
const HtmlWebpackPlugin = require("html-webpack-plugin");
const webpack = require("webpack");

// Every REACT_APP_* variable in the environment is baked into the bundle
// below, so release builds (scripts/package.sh) set JOIN_FLOW_IGNORE_DOTENV
// to keep a developer's local .env out of the shipped bundle.
if (!process.env.JOIN_FLOW_IGNORE_DOTENV) {
  require("dotenv").config({ path: resolve(__dirname, "../.env") });
}

module.exports = {
  resolve: {
    extensions: [".ts", ".tsx", ".js", ".jsx", ".ts", ".tsx"],
    fallback: { querystring: require.resolve("querystring-es3") }
  },
  context: resolve(__dirname, "../src"),
  output: {
    filename: "bundle.js",
    path: resolve(__dirname, "../../join-block/build/join-flow")
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        use: ["babel-loader", "source-map-loader"],
        exclude: /node_modules/
      },
      {
        test: /\.tsx?$/,
        use: ["babel-loader"]
      },
      {
        test: /\.css$/,
        use: [
          "style-loader",
          { loader: "css-loader", options: { importLoaders: 1 } }
        ]
      },
      {
        test: /\.(scss|sass)$/,
        use: [
          "style-loader",
          { loader: "css-loader", options: { importLoaders: 1 } },
          "resolve-url-loader",
          "sass-loader"
        ]
      },
      {
        test: /\.(jpe?g|png|gif|svg|woff2)$/i,
        type: "asset/resource"
      }
    ]
  },
  plugins: [
    new HtmlWebpackPlugin({
      template: resolve(__dirname, "../public/index.html")
    }),
    new webpack.DefinePlugin({
      "process.env": Object.keys(process.env).reduce((env, key) => {
        if (key.startsWith("REACT_APP_")) {
          env[key] = JSON.stringify(process.env[key]);
        }
        return env;
      }, {})
    })
  ],
  performance: {
    hints: "warning"
  }
};
