import Link from "next/link";
import type { ReactNode } from "react";

import { Button } from "@pcle/ui/components/button";
import { cn } from "@pcle/ui/lib/utils";

import { logoutAction } from "@/app/actions/auth";
import { pathsFor } from "@/lib/navigation";

/**
 * Shared frame for the signed-in pages: a header offering the same paths the
 * menu offers, and a way out, plus a readable content column.
 *
 * The header used to write its own list of links, which is how it came to
 * offer a different set from the menu — an administrator was shown
 * Administration on one screen and not on the next. Both now render
 * pathsFor(), so a path added in one place appears in both or in neither.
 *
 * The wordmark goes to the menu. It used to go to My Training, which was
 * reasonable while the menu was a dead end; now that signing in lands there,
 * the wordmark doing the conventional thing is also the useful thing.
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
  const paths = await pathsFor();

  return (
    /*
      flex-1, not min-h-screen. The body is a full-height flex column with the
      version stamp as its last child; a screen-height shell would push that
      below the fold on every page, which is a footer nobody ever reads.
    */
    <div className="flex flex-1 flex-col bg-zinc-50">
      <header className="border-b border-zinc-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-6 py-4">
          <Link
            href="/"
            className="text-sm font-semibold text-zinc-950 hover:underline"
          >
            Platform CLE
          </Link>

          {paths.map((path) => (
            <Button
              key={path.href}
              variant="link"
              size="sm"
              className="px-0 text-zinc-500"
              nativeButton={false}
              render={
                path.external ? (
                  <a href={path.href} />
                ) : (
                  <Link href={path.href} />
                )
              }
            >
              {path.label}
            </Button>
          ))}

          <form action={logoutAction} className="ml-auto">
            <Button
              type="submit"
              variant="link"
              size="sm"
              className="px-0 text-zinc-500"
            >
              Log out
            </Button>
          </form>
        </div>
      </header>

      <main
        className={cn("mx-auto w-full px-6 py-10", wide ? "max-w-6xl" : "max-w-3xl")}
      >
        {children}
      </main>
    </div>
  );
}
