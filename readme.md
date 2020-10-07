# Join-Greens

## Quickstart

* Ensure you have a recent node version, composer and docker installed.

* Install dependencies and boot the wordpress instance.

```bash
composer install
npm install
cp .env.template .env
$EDITOR .env
docker-compose up
```

* Start the frontend dev server.

```bash
npm start
```

* Go to <http://localhost:8080/wp-admin/plugins.php> and enable the 'Join' plugin.

* Navigate to any page in the editor and type '/' or press '+'

* Behold, the 'Join' block!
