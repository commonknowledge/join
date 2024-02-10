// shared config (dev and prod)
const { resolve } = require("path");
const HtmlWebpackPlugin = require("html-webpack-plugin");
const webpack = require("webpack");

const parsed = require("dotenv").config({ path: resolve(__dirname, '../.env') });

module.exports = {
  resolve: {
    extensions: [".ts", ".tsx", ".js", ".jsx", ".ts", ".tsx"],
    fallback: { "querystring": require.resolve("querystring-es3") }
  },
  context: resolve(__dirname, "../src"),
  output: {
    filename: "bundle.js",
    path: resolve(__dirname, "../../join-block/build/join-flow"),
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
        type: 'asset/resource'
      }
    ]
  },
  plugins: [
    new HtmlWebpackPlugin({
      template: resolve(__dirname, "../public/index.html")
    }),
    new webpack.DefinePlugin(
      Object.assign(
        { "process.env": JSON.stringify({}) },
        ...Object.keys(process.env)
          .filter((x) => x.startsWith("REACT_APP_"))
          .map((key) => ({
            [`process.env.${key}`]: JSON.stringify(process.env[key])
          }))
      )
    )
  ],
  performance: {
    hints: "warning"
  }
};
