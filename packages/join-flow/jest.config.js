module.exports = {
  preset: '@wordpress/jest-preset-default',
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/src/**/*.test.(ts|tsx)'],
  moduleNameMapper: {
    '\\.(css|scss|svg|png)$': '<rootDir>/__mocks__/fileMock.js',
  },
  transformIgnorePatterns: [
    '/node_modules/(?!(lodash-es)/)',
  ],
};
