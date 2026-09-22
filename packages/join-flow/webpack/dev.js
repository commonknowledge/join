// development config
const { merge } = require("webpack-merge");
const commonConfig = require("./common");
const path = require("path");

module.exports = merge(commonConfig, {
  mode: "development",
  entry: [
    "regenerator-runtime/runtime",
    "react-hot-loader/patch", // activate HMR for React
    "webpack/hot/only-dev-server", // bundle the client for hot reloading, only- means to only hot reload for successful updates
    "../src/index.tsx" // the entry point of our app
  ],
  devServer: {
    hot: true, // enable HMR on the server
    port: 3000,
    static: path.resolve(__dirname, "../../join-block/build/join-flow"),
    headers: {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, PATCH, OPTIONS",
      "Access-Control-Allow-Headers": "X-Requested-With, content-type, Authorization"
    },
    // The standalone harness has no WordPress backend, so stub the REST
    // endpoints the form posts to (mirrors mockRestEndpoints in join-e2e).
    // Bodies are logged so the payload can be inspected in the terminal.
    setupMiddlewares: (middlewares, devServer) => {
      devServer.app.use(require("express").json());
      devServer.app.post("/join/v1/:resource", (req, res) => {
        console.log(`[mock] POST /join/v1/${req.params.resource}`, JSON.stringify(req.body));
        res.json({});
      });
      return middlewares;
    }
  },
  devtool: "cheap-module-source-map",
  performance: {
    // Don't care that the bundle is too large in dev env
    hints: false
  }
});
