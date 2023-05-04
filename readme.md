# Join Flow

## Overview

A version of the Join Flow is [currently used by The Green Party in production](https://join.greenparty.org.uk/join-us/). 

A version of this code is incorporated into what was called [Monorail](https://github.com/commonknowledge/ckmap) which runs the [Nurses United join form](https://join.nursesunited.org.uk/#). 

In this code we currently call out to various systems the Green Party uses - Auth0, ChargeBee, Go Cardless and so on. In the Nurses United version [we communicate with Stripe](https://github.com/commonknowledge/ckmap/blob/main/ckmap/external/models/stripe.py), albeit in Python. The underlying React frontend code is largely unchanged in the Monorail variant.

## Code Structure

This is a monorepo, containing 3 packages:

- `packages/theme`: A Bootstrap based WordPress theme implementing the design system used by the join site and The Green Party brand. This can be removed, as we want this to sit in other environments.

- `packages/join-flow`: A React project (using `create-react-app`) implementing the join flow frontend. i.e. the form itself, its fields and the passage through it.

- `packages/join-block`: A WordPress Gutenberg block that allows the join flow to be dropped into a WordPress page, along with the backend join logic that communicates services to make the person a member - in the Green Party instance Auth0, ChargeBee and Go Cardless. The Gutenberg block is very simple and uses [Carbon Fields](https://carbonfields.net/) to render onto the page. 

Probably the `join-block` and the `join-flow` can be integrated with one another as one single package. They don't really work without one another in any case. Simply at the time we wanted the `join-flow` to be agnostic of any particular backend. Though it is quite nice to be able to test them distinctly. 

It is also worth noting that the native way of creating [WordPress Blocks is React](https://developer.wordpress.org/block-editor/how-to-guides/block-tutorial/) - this is also the reason the flow itself is in React. There is a future way of coding this where Join Flow itself, with all its powers is a complete native block in the WordPress ecosystem, rather than how it is now, which is a freestanding piece of code, [rendered into WordPress by a Carbon Fields block, but otherwise having no relation to the way in which Gutenberg blocks do React](https://github.com/commonknowledge/join/blob/master/packages/join-block/lib/blocks.php#L6-L44).

## How does the Join Flow work?

We wanted to make the ability to join the Green Party widely available.

To do so, it is useful for the ability to join to be distributed across the Green Party's WordPress network and neatly dropped into any page or post. Therefore this join flow is written as a WordPress block, that launches a form flow written in React.

This also allows the Green Party to create highly situational and bespoke join pages on the fly, by just throwing together a WordPress page.

Hopefully this will allow The Green Party to be highly reactive to ongoing political events and take the opportunity to gain members when the moment arrives.

We hope to re-use this as a generalisable way of joining organisations and taking payment.

The general user flow, including technical detail is:

1. User visits a WordPress page with the "Join Form" block on it. This prompts the user for their email address and encourages them to join the Green Party.
2. When they enter their email address and press the button they are directed to another page with the block "Join Form Fullscreen Takeover" on it. This is a React application that takes them through the join process and validates their details client side.
3. When the user is done with the form, the React application sends a POST request is sent to an special endpoint in the [WordPress REST API](https://developer.wordpress.org/rest-api/). This is setup by a WordPress plugin, which also adds the above mentioned blocks to the WordPress site. This handles the server side logic needed to make someone a member of the Green Party. It creates them on Chargebee, sets up payment and then creates their user on Auth0 so they can login to the Green Party digital estate.
4. On success, the React application is sent a JSON response. The user is redirected to a success page. This page can be any page on the WordPress site. This is setup when the "Join Form Fullscreen Takeover" block is setup.
5. All done!

## WordPress Blocks included

These [WordPress blocks](https://wordpress.org/support/article/blocks/) form the basis of the current main way of [joining The Green Party](https://join.greenparty.org.uk/).

They are designed to have the copy changed - nothing is hard coded. This is intended to allow the copy to be iterated to improve the performance of this landing page.

- **Join Form Fullscreen Takeover** The whole join flow experience. Add this to one page and you are ready to allow someone to join. The React application takes over the whole page, so the rest of the page will be ignored. Also works on posts.
- **Join Header** A large image and a slogan to encourage someone to join the Green Party. As seen on the top of the current join page.
- **Join Form** An email address field which lets someone enter their email address, press a button and launch the join flow. When they arrive at the join flow, their email address will be automatically filled in. As seen on the middle of the current join page.
- **Membership Benefits** A listing of membership benefits. You can add as many as you like and an icon to illustrate them. As seen on the bottom of the current join page.

You can see these final three employed on the [Nurses United pre-join page](https://www.nursesunited.org.uk/join/). While this version of the join form uses the Monorail application rather than hosting things on WordPress for the whole flow like the Green Party, the blocks on Nurses United WordPress site that render this pre-join page are [basically copy and pasted into the Nurses United theme](https://github.com/commonknowledge/nurses-united-website/blob/master/web/app/themes/nurses-united-2020/app/blocks.php).

These blocks are lightly styled by a simple Bootstrap based WordPress theme that sticks to The Green Party brand. This is also found within this repository.

Without the WordPress theme the blocks and the join flow are functional, but render as plain HTML without styling.

These Gutenberg blocks use [Carbon Fields](https://carbonfields.net/) to administer and render themselves.

## Build and Deployment Workflow

### Build

Install dependencies and build

```bash
yarn
yarn composer
yarn build
```

**NOTE:** When using linux (including WSL). You may encounter an error related to the `gifsicle` package when running `yarn build`. Add the following to the `resolutions` and `devDependecies` section of the `packages/join-flow/package.json` file and re-run `yarn` and `yarn build`

```bash
"gifsicle": "4.0.1"
```

Results in deployable artifacts:

- `packages/join-block`: Join block plugin

- `packages/theme/dist`: WordPress plugin

### Deploying

In order to deploy this work, you need to create a WordPress plugin and theme and add them to the WordPress instance as needed.

1. Run the build commands above to compile the React application.
2. Run `sh scripts/package.sh` on linux this can be run as `./scripts/package.sh`. This will create zip files of the WordPress plugin and theme in the root directory.
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

```bash
cp .env.template .env
```

- Boot the site

```bash
yarn start:frontend
```

- Open <http://localhost:3000>
