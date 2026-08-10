# syntax=docker/dockerfile:1

FROM node:20-alpine AS base
WORKDIR /repo

# ---- Dependencies ----

FROM base AS deps
COPY package.json package-lock.json ./
COPY apps/web/package.json apps/web/package.json
RUN npm ci --workspace=apps/web --include-workspace-root

# ---- Development ----

FROM base AS dev
ENV NODE_ENV=development
ENV PORT=3000

COPY --from=deps /repo/node_modules ./node_modules
COPY . .

EXPOSE 3000

CMD ["npm", "run", "dev", "--workspace=apps/web", "--", "-H", "0.0.0.0"]

# ---- Build ----

FROM base AS builder
COPY --from=deps /repo/node_modules ./node_modules
COPY . .
RUN npm run build --workspace=apps/web

# ---- Production ----

FROM base AS runner
ENV NODE_ENV=production
ENV PORT=3000

RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nextjs

COPY --from=builder /repo/apps/web/public ./apps/web/public
COPY --from=builder --chown=nextjs:nodejs /repo/apps/web/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /repo/apps/web/.next/static ./apps/web/.next/static

USER nextjs
EXPOSE 3000

CMD ["node", "apps/web/server.js"]