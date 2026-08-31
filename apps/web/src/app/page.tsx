import Link from "next/link";

import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";

import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import { getMe, wordpressFetch, WORDPRESS_SITE_URL } from "@/lib/wordpress";

type WordPressSite = {
  name: string;
  description: string;
};

async function getWordPressSite(): Promise<WordPressSite> {
  return wordpressFetch("/") as Promise<WordPressSite>;
}

/**
 * What may the signed-in reader do?
 *
 * Fails closed, the same way the shared header does: a stale or expired token
 * means no staff paths rather than a broken page. Every route is guarded
 * server-side regardless — this only decides what to offer.
 */
async function whoIsThis(): Promise<{ author: boolean; admin: boolean }> {
  try {
    const me = await getMe();
    return { author: me.can_author, admin: me.is_admin };
  } catch {
    return { author: false, admin: false };
  }
}

/** One way in, with a word about where it leads. */
function Path({
  href,
  title,
  description,
}: {
  href: string;
  title: string;
  description: string;
}) {
  return (
    <Link href={href} className="block transition hover:shadow-sm">
      <Card className="h-full p-6 text-left hover:ring-foreground/20">
        <CardContent className="p-0">
          <h2 className="text-lg font-medium text-zinc-950">{title}</h2>
          <p className="mt-1 text-sm text-zinc-600">{description}</p>
        </CardContent>
      </Card>
    </Link>
  );
}

/**
 * The front door.
 *
 * It used to be the site's name and tagline and nothing else — the only screen
 * in the app with nothing to click, so anyone arriving read a sentence and
 * stopped. What someone needs here depends entirely on who they are, and the
 * server already knows: whether they are signed in, and whether they teach.
 */
export default async function Home() {
  const site = await getWordPressSite();
  const signedIn = await isAuthenticated();
  const { author, admin } = signedIn
    ? await whoIsThis()
    : { author: false, admin: false };

  return (
    <main className="flex min-h-screen items-center justify-center bg-zinc-50 px-6 py-16">
      <div className="w-full max-w-3xl text-center">
        <h1 className="text-5xl font-semibold tracking-tight text-zinc-950">
          {decodeEntities(site.name)}
        </h1>

        <p className="mt-4 text-xl text-zinc-600">
          {decodeEntities(site.description)}
        </p>

        {signedIn ? (
          <>
            <div className="mt-10 grid gap-4 sm:grid-cols-2">
              <Path
                href="/my-training"
                title="My Training"
                description="The programmes you are enrolled in, and how far through them you are."
              />

              {/*
                Only shown to teaching staff. A participant seeing a door they
                cannot open is worse than not knowing it is there.
              */}
              {author && (
                <>
                  <Path
                    href="/builder"
                    title="Build"
                    description="Write and organise programmes, units, modules and quizzes."
                  />

                  <Path
                    href="/reports"
                    title="Reports"
                    description="Who is enrolled, what they have completed, and what is outstanding."
                  />
                </>
              )}

              {/*
                Administration stays in WordPress: enrolment, accounts and
                settings live there and are not worth a second implementation.
                Offered only when the public WordPress URL is known, because
                the address this server uses for the API is not always one a
                browser can reach.
              */}
              {admin && WORDPRESS_SITE_URL && (
                <Path
                  href={`${WORDPRESS_SITE_URL}/wp-admin/`}
                  title="Administration"
                  description="Accounts, enrolment and site settings, in WordPress."
                />
              )}
            </div>

            {!author && (
              <p className="mt-6 text-sm text-zinc-500">
                Looking for something you cannot see here? Contact your
                programme administrator.
              </p>
            )}
          </>
        ) : (
          <div className="mt-10">
            <Button nativeButton={false} render={<Link href="/login" />}>
              Sign in
            </Button>

            <p className="mt-4 text-sm text-zinc-500">
              Course materials are available to enrolled participants.
            </p>
          </div>
        )}
      </div>
    </main>
  );
}
