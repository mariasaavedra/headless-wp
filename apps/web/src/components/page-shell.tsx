import Link from "next/link";
import type { ReactNode } from "react";

import { Button } from "@pcle/ui/components/button";

import { logoutAction } from "@/app/actions/auth";
import { getMe } from "@/lib/wordpress";

/**
 * Is the signed-in user someone who can build courses?
 *
 * The app had no notion of an instructor: every screen assumed a participant.
 * This is the one question the shared frame needs answered, and it fails
 * closed — a stale or expired token means no builder link, not a broken page.
 * The route itself is guarded server-side regardless; this only decides
 * whether to advertise it.
 */
async function canAuthor(): Promise<boolean> {
  try {
    return (await getMe()).can_author;
  } catch {
    return false;
  }
}

/**
 * Shared frame for the signed-in pages: a header that always offers a way back
 * to My Training and a way out, plus a readable content column.
 */
export default async function PageShell({ children }: { children: ReactNode }) {
  const showBuilder = await canAuthor();

  return (
    <div className="min-h-screen bg-zinc-50">
      <header className="border-b border-zinc-200 bg-white">
        <div className="mx-auto flex max-w-3xl items-center gap-4 px-6 py-4">
          <Link
            href="/my-training"
            className="text-sm font-semibold text-zinc-950 hover:underline"
          >
            Platform CLE
          </Link>

          {showBuilder && (
            <Button
              variant="link"
              size="sm"
              className="px-0 text-zinc-500"
              nativeButton={false}
              render={<Link href="/builder" />}
            >
              Build
            </Button>
          )}

          <form action={logoutAction} className="ml-auto">
            <Button type="submit" variant="link" size="sm" className="px-0 text-zinc-500">
              Log out
            </Button>
          </form>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-6 py-10">{children}</main>
    </div>
  );
}
