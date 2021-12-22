# The Green Party of England and Wales Join Flow

## Overview

This is a monorepo, containing 3 packages:

- `packages/theme`: A Bootstrap based WordPress theme implementing the design system used by the join site and The Green Party brand.

- `packages/join-flow`: A React project (using `create-react-app`) implementing the join flow frontend.

- `packages/join-block`: A WordPress Gutenberg block that allows the join flow to be dropped into a WordPress page, along with the backend join logic that communicates services to make the person a member.

## How does the Join Flow work?

In order to enable joining the Green Party to be distributed across the Green Party's WordPress network and neatly dropped into any page or post, this join flow is written as a WordPress block, that launches a form flow written in React. This also allows the Green Party to create highly situational and bespoke join pages on the fly, by just throwing together a WordPress page. Hopefully this will allow The Green Party to be highly reactive to ongoing political events and take the opportunity to gain members when the moment arrives.

The general user flow, including technical detail is:

1. User visits a WordPress page with the "Join Form" block on it. This prompts the user for their email address and encourages them to join the Green Party.
2. When they enter their email address and press the button they are directed to another page with the block "Join Form Fullscreen Takeover" on it. This is a React application that takes them through the join process and validates their details client side.
3. When the user is done with the form, a POST request is sent to an special endpoint in the [WordPress REST API](https://developer.wordpress.org/rest-api/). This is setup by a WordPress plugin, which also adds the above mentioned blocks to the WordPress site. This handles the server side logic needed to make soneone a member of the Green Party. It creates them on Chargebee, sets up payment and then creates their user on Auth0 so they can login to the Green Party digital estate.
4. On success, the React application is sent a JSON response. The user is redirected to a success page of the initial page with "Join Form" on it's choosing.
5. All done!

## WordPress Blocks Included

These WordPress blocks form the basis of the current main way of [joining The Green Party](https://join.greenparty.org.uk/).

They are designed to have the copy changed - nothing is hard coded. This is intended to allow the copy to be iterated to improve the performance of this landing page.

- **Join Form Fullscreen Takeover** The whole join flow experience. Add this to one page and you are ready to allow someone to join. The React application takes over the whole page, so the rest of the page will be ignored. Also works on posts.
- **Join Header** A large image and a slogan to encourage someone to join the Green Party. As seen on the top of the current join page.
- **Join Form** An email address field which lets someone enter their email address, press a button and launch the join flow. When they arrive at the join flow, their email address will be automatically filled in. As seen on the middle of the current join page.
- **Membership Benefits** A listing of membership benefits. You can add as many as you like and an icon to illustrate them. As seen on the bottom of the current join page.

These blocks are lightly styled by a simple Bootstrap based WordPress theme that sticks to The Green Party brand. This is also found within this repository.

Without the WordPress theme installed and turned on the blocks and the join flow are functional, but render as plain HTML without styling.

## Build and Deployment Workflow

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

In order to deploy this work, you need to create a WordPress plugin and theme and add them to the WordPress instance as needed.

1. Run the build commands above to compile the React application.
2. Run `sh scripts/package.sh`. This will create zip files of a WordPress plugin and a WordPress theme in the root directory.
3. Upload them to a WordPress site and activate both.

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

- Start an instance of WordPress by running `docker compose up` in the root directory of this project. This will launch an instance of WordPress on <http://localhost:8080>.

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
