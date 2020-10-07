
const path = require('path');
const glob = require('glob');

module.exports = {
  entry: {
    'bundle.js': glob
		.sync('node_modules/uk-greens-join-flow/build/static/?(js|css|media)/*.?(js|css|png|woff|woff2|eot|ttf|svg)')
      .map(f => path.resolve(__dirname, f)),
  },
  output: {
		filename: 'bundle.js',
		path: path.resolve('dist/join-flow')
  },
  module: {
    rules: [
      {
        test: /.css$/,
        use: ['style-loader', 'css-loader'],
      },
      { test: /.(png|woff|woff2|eot|ttf|svg)$/, use: ['url-loader?limit=100000'] }
    ],
  },
};
