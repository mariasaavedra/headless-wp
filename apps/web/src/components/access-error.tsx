import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import type { ReactElement } from "react";

import PageShell from "@/components/page-shell";
import { WordPressApiError } from "@/lib/wordpress";

/**
 * Turns a refused curriculum request into the right outcome for the reader.
 *
 * WordPress is the authority here, and it distinguishes three cases that
 * deserve three different answers:
 *
 *   401 — the token is missing or expired → sign in again.
 *   404 — no such item, or not of the type this route serves.
 *   403 — the item exists but this reader is not enrolled in its programme.
 *
 * 403 is deliberately not a 404: the reader is signed in and the content is
 * real, so telling them enrollment is what's missing is more useful than
 * pretending the page doesn't exist. It leaks only the fact that an id exists,
 * which they already had.
 *
 * Anything else is a genuine fault and is rethrown for the error boundary.
 */
export function renderAccessError(error: unknown): ReactElement {
  if (!(error instanceof WordPressApiError)) {
    throw error;
  }

  if (error.status === 401) {
    redirect("/login");
  }

  if (error.status === 404) {
    notFound();
  }

  if (error.status !== 403) {
    throw error;
  }

  return (
    <PageShell>
      <h1 className="text-2xl font-semibold text-zinc-950">
        You are not enrolled in this programme
      </h1>

      <p className="mt-3 text-zinc-600">
        This content is limited to enrolled participants. If you believe you
        should have access, contact your programme administrator.
      </p>

      <Link
        href="/my-training"
        className="mt-6 inline-block text-sm font-medium text-zinc-950 underline"
      >
        Back to My Training
      </Link>
    </PageShell>
  );
}
