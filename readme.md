# Join-Greens

## Overview

This is a monorepo, containing 3 packages:

- `packages/theme`: A Bootstrap based WordPress theme implementing the design system used by the join site.

- `packages/join-flow`: A React project (using create-react-app) implementing the join form frontend.

- `packages/join-block`: A WordPress Gutenberg block that allows the join form to be dropped into a WordPress page, along with the backend join logic that communicates services to make the person a member.

## Build & deploy workflow

### Build

Install dependencies and build

```bash
yarn
yarn composer
yarn build
```

Results in deployable artifacts:

- `packages/join-block`: Join block plugin

- `packages/theme/dist`: WordPress plugin

### Deploying

TODO

## Developer quickstart

### Running the whole system as a WordPress site

- Ensure you have a recent Node.js >= v12, Yarn, Composer and Docker installed.

- Install dependencies

```bash
yarn
yarn composer
```

- Copy the .env template into place, open it and add any missing configurations

```bash
cp .env.template .env
```

- Boot the site

```bash
yarn start
```

#### To use join form 'in-place' in a WordPress site

- Open <http://localhost:8080/wp-admin/plugins.php> and enable the 'Join' plugin.

- Navigate to any page in the editor and type '/' or press '+'

- Add a 'join' block to the page.

#### To work on the join form as a self-contained React application (with live-reload, etc)

- Open <http://localhost:3000>

### Running the front end in isolation (without a backend)

- Ensure you have a recent Node.js >= v12 and Yarn installed.

- Install dependencies

```bash
yarn
```

- Copy the .env template into place, open it and add any missing configurations

````bash
cp .env.template .env
``

- Boot the site

```bash
yarn start:frontend
````

- Open <http://localhost:3000>
