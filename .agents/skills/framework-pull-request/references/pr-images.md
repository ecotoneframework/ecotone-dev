## Images in a pull request body

Images are attached to the pull request, never committed and never hosted elsewhere. Keep the working files under
`var/pr-images/`, which git ignores: the body, the `.mmd` sources, the rendered diagrams and the screenshots.

The body references an image by its path relative to the body file, with an alt text that says what it shows:

```markdown
![After: the next message of the key follows the failed one into the dead letter](after.png)
```

`--attach` uploads the file and rewrites that reference to the uploaded URL. It needs `gh` 2.99 or newer
(`gh --version`), takes PNG, JPEG, GIF, WebP and SVG up to 10 MB, and is repeated once per image:

```bash
gh pr create --title "<title>" --body-file var/pr-images/body.md \
    --attach var/pr-images/before.png --attach var/pr-images/after.png

gh pr edit <number> --body-file var/pr-images/body.md --attach var/pr-images/held-keys-dark.png
```

An attached file the body does not reference is appended to the end of the body, so reference every image where it
belongs. When editing a body that already has uploaded images, keep their `https://github.com/user-attachments/...`
URLs and attach only the new files. Read the body back afterwards (`gh pr view <number> --json body`): no local
path may be left in it.

### A diagram

For anything that changes a message flow: handler chains, async processing, sagas, interceptor pipelines. Sequence
diagrams for flows, flowcharts for decision logic. For a changed flow, one diagram before and one after.

```bash
mkdir -p var/pr-images
cat > var/pr-images/order-flow.mmd <<'MMD'
sequenceDiagram
    participant User
    participant CommandBus
    participant Handler
    User->>CommandBus: PlaceOrder
    CommandBus->>Handler: #[CommandHandler]
    Handler-->>User: OrderPlaced event
MMD
bin/render-mermaid var/pr-images/order-flow.mmd   # writes var/pr-images/order-flow.png
```

A diagram with a syntax error fails the render. Look at the PNG before attaching it.

### Ecotone Pulse screenshots

For a change to what a Pulse page shows.

1. Run the demo from the worktree, on its own project name and ports so it does not collide with a demo that is
   already running (the default one on port 8080 serves another checkout):
   ```bash
   COMPOSE_PROJECT_NAME=ecotone-demo-<worktree> DEMO_PULSE_PORT=8180 DEMO_STOREFRONT_PORT=8100 \
   DEMO_TICKETING_PORT=8101 DEMO_JAEGER_PORT=16786 DEMO_DATABASE_PORT=8181 DEMO_RABBITMQ_MANAGEMENT_PORT=15772 \
   docker compose -f demo/docker-compose.yml up -d --build --wait
   ```
   Twig, CSS and JS edits are live; after a PHP change restart the `pulse` service.
2. Bring the page into the state the change is about: a page with no data proves nothing. `demo/README.md` lists
   what produces traffic, failures and traces (for example
   `docker compose -f demo/docker-compose.yml exec storefront php artisan demo:rush <concertId> --buyers=20`).
3. Screenshot the changed page with Playwright, in dark and in light when the change is visual:
   ```bash
   npx --yes playwright screenshot --channel chrome --color-scheme dark --viewport-size "1440,900" --full-page \
       --wait-for-timeout 3000 http://localhost:8180/<page> var/pr-images/<page>-dark.png
   ```
   A state that needs a click first (an open dialog, an expanded row) is taken with a Playwright script or the
   Playwright browser tools instead of the one-line command.
4. When an existing page changed, take the same page from `main` for a "before" (the default demo, or the same
   stack on a checkout of `main`).
5. Look at every screenshot before uploading it: it must show the changed element, and no error page.
6. Reference the images under a **Screenshots** heading in *Description of Changes* and attach them.
7. Stop the stack: `COMPOSE_PROJECT_NAME=ecotone-demo-<worktree> docker compose -f demo/docker-compose.yml down -v`.
