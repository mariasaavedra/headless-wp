import Link from "next/link";
import type { ReactNode } from "react";

import { Button } from "@pcle/ui/components/button";
import { cn } from "@pcle/ui/lib/utils";

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
 * Shared frame for the signed-in pages: a header offering the same paths the
 * front page offers, and a way out, plus a readable content column.
 *
 * The wordmark goes home rather than to My Training. It used to go to My
 * Training, which was reasonable while the front page was a dead end — there
 * was nothing there to go back to. Now that it is the one screen listing every
 * path a reader has, the wordmark doing the conventional thing costs nothing:
 * My Training has its own link beside it.
 */
export default async function PageShell({
  children,
  wide = false,
}: {
  children: ReactNode;
  /**
   * Widens the content column. The default is sized for prose, which is what
   * almost every screen here is; a cohort table is the exception and gets
   * clipped in it.
   */
  wide?: boolean;
}) {
  const showBuilder = await canAuthor();

  return (
    <div className="min-h-screen bg-zinc-50">
      <header className="border-b border-zinc-200 bg-white">
        <div className="mx-auto flex max-w-3xl items-center gap-4 px-6 py-4">
          <Link
            href="/"
            className="text-sm font-semibold text-zinc-950 hover:underline"
          >
            Platform CLE
          </Link>

          <Button
            variant="link"
            size="sm"
            className="px-0 text-zinc-500"
            nativeButton={false}
            render={<Link href="/my-training" />}
          >
            My Training
          </Button>

          {showBuilder && (
            <>
              <Button
                variant="link"
                size="sm"
                className="px-0 text-zinc-500"
                nativeButton={false}
                render={<Link href="/builder" />}
              >
                Build
              </Button>

              <Button
                variant="link"
                size="sm"
                className="px-0 text-zinc-500"
                nativeButton={false}
                render={<Link href="/reports" />}
              >
                Reports
              </Button>
            </>
          )}

          <form action={logoutAction} className="ml-auto">
            <Button type="submit" variant="link" size="sm" className="px-0 text-zinc-500">
              Log out
            </Button>
          </form>
        </div>
      </header>

      <main className={cn("mx-auto px-6 py-10", wide ? "max-w-6xl" : "max-w-3xl")}>
        {children}
      </main>
    </div>
  );
}
