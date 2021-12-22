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

```bash
docker compose up
```

#### To use join form 'in-place' in a WordPress site

- Start an instance of WordPress by running `docker compose up` in the root directory of this project.

- Open <http://localhost:8080/wp-admin/plugins.php> and enable the 'Join' plugin.

- Add the "Join Form Fullscreen Takeover" block to a WordPress page. This will be where the join form itself will live. It can be linked to directly. Save this page.

- Wherever you want the join form to be launched from, add the "Join Form" WordPress block. This allows the email address to be pre-filled for the person wanting to join. Connect this to the page you have just created. Save the post.

- You will now have a working join form that is working from the code on your machine. If you modify the code in `packages/join-flow` this will update the join flow. If you modify the code in `packages/join-block` this will change the backend logic of the WordPress plugin.

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
