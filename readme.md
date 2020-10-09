# Join-Greens

## Overview

This is a monorepo, containing 3 packages:

- `packages/theme`: A bootstrap-based wordpress theme implementing the design system used by the join site.

- `packages/join-flow`: A React project (using create-react-app) implementing the join form frontend.

- `packages/join-block`: A wordpress Gutenberg block that allows the join form to be dropped into a wordpress page, along with the backend join logic.

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

- `packages/theme/dist`: Wordpress plugin

### Deploying

TODO

## Developer quickstart

### Running the whole system as a Wordpress site

- Ensure you have a recent node >= v12, yarn, composer and docker installed.

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

#### To use join form 'in-place' in a wordpress site

- Open <http://localhost:8080/wp-admin/plugins.php> and enable the 'Join' plugin.

- Navigate to any page in the editor and type '/' or press '+'

- Add a 'join' block to the page.

#### To work on the join form as a self-contained react app (with live-reload, etc)

- Open <http://localhost:3000>

### Running the front end in isolation (without a backend)

- Ensure you have a recent node >= v12 and yarn installed.

- Install dependencies

```bash
yarn
```

- Copy the .env template into place, open it and add any missing configurations

```bash
cp .env.template .env
``

- Boot the site

```bash
yarn start:frontend
```

- Open <http://localhost:3000>
