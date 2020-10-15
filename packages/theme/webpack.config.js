const path = require("path");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const { extname } = require("path");

module.exports = {
	mode: process.env.NODE_ENV === "production" ? "production" : "development",
	entry: ["./scss/index.scss"],
	output: {
		path: path.resolve(__dirname, "dist"),
		filename: "index.js",
	},
	module: {
		rules: [
			{
				test: /\.scss$/,
				use: [
					...(process.env.NODE_ENV === "production"
						? [
								{
									loader: "file-loader",
									options: {
										name: "style.css",
									},
								},

								{
									loader: "extract-loader",
								},
						  ]
						: [
							'style-loader'
						]),
					{
						loader: "css-loader?-url",
					},
					{
						loader: "postcss-loader",
					},
					{
						loader: "sass-loader",
					},
				],
			},
		],
	},
	plugins: [
		new CopyWebpackPlugin({
			patterns: [{ from: "static" }, { from: "fonts", to: "fonts/" }],
		}),
	],
	devServer: {
		writeToDisk: (path) => extname(path) === '.php'
	}
};
