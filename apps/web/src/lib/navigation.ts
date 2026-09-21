import { getMe, WORDPRESS_SITE_URL } from "@/lib/wordpress";

/** One place a reader may go, described once for everywhere that offers it. */
type Path = {
  href: string;
  /** What the header calls it. */
  label: string;
  /** What the menu says it is for. */
  description: string;
  /** Leaves the app — WordPress, in practice. */
  external?: boolean;
};

/**
 * The paths a reader has, in the order they are offered.
 *
 * One list, because there were two: the header wrote its own and the front
 * door wrote another, and they had already drifted — the header never
 * mentioned Administration, so an administrator was offered it on one screen
 * and not on the next. Two hand-maintained lists of the same thing do not
 * stay in step; this removes the second one rather than correcting it.
 *
 * Fails closed. A stale or expired token means the participant's path only,
 * not a broken page and not a staff path offered by accident. Every route is
 * guarded server-side regardless — this only decides what to advertise.
 */
async function pathsFor(): Promise<Path[]> {
  let author = false;
  let admin = false;

  try {
    const me = await getMe();
    author = me.can_author;
    admin = me.is_admin;
  } catch {
    author = false;
    admin = false;
  }

  const paths: Path[] = [
    {
      href: "/my-training",
      label: "My Training",
      description:
        "The programmes you are enrolled in, and how far through them you are.",
    },
  ];

  if (author) {
    paths.push(
      {
        href: "/builder",
        label: "Build",
        description:
          "Write and organise programmes, units, modules and quizzes.",
      },
      {
        href: "/reports",
        label: "Reports",
        description:
          "Who is enrolled, what they have completed, and what is outstanding.",
      }
    );
  }

  /*
   * Administration stays in WordPress: enrolment of whole cohorts, accounts
   * and site settings live there and are not worth a second implementation.
   * Offered only when the public WordPress URL is known, because the address
   * this server uses for the API is not always one a browser can reach.
   */
  if (admin && WORDPRESS_SITE_URL) {
    paths.push({
      href: `${WORDPRESS_SITE_URL}/wp-admin/`,
      label: "Administration",
      description: "Accounts, enrolment and site settings, in WordPress.",
      external: true,
    });
  }

  return paths;
}

export { pathsFor };
export type { Path };
