# headless-wp

npm workspaces monorepo.

## Structure

```text
/
├── apps/
│   └── web/       # Next.js app (App Router, TypeScript, Tailwind CSS)
├── docker/
├── package.json
├── package-lock.json
├── docker-compose.yml
└── README.md
```

## Local development

```bash
npm install
npm run dev
```

The app runs at http://localhost:3000.

Other root scripts (delegate to the `apps/web` workspace):

```bash
npm run build
npm run lint
npm run start
```

## Docker

```bash
docker compose up --build
```

The app runs at http://localhost:3000.
