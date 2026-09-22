import Link from "next/link";
import { redirect } from "next/navigation";

import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";

import { logoutAction } from "@/app/actions/auth";
import { isAuthenticated } from "@/lib/auth";
import { decodeEntities } from "@/lib/html";
import { pathsFor, type Path } from "@/lib/navigation";
import { wordpressFetch } from "@/lib/wordpress";

/*
 * Never prerendered. What this page shows depends on who is asking — the
 * signed-in reader's role decides which paths are offered — and the site name
 * and tagline come from WordPress at request time. Static generation would
 * both bake in a signed-out page for everyone and make the build depend on
 * WordPress being reachable, because the fetch above runs before the cookie
 * read that would otherwise mark this route dynamic.
 */
export const dynamic = "force-dynamic";

type WordPressSite = {
  name: string;
  description: string;
};

/**
 * The site's own name and tagline, or the app's if WordPress cannot say.
 *
 * Never throws. This is the only page an anonymous visitor can reach that
 * asks WordPress anything, so an unreachable backend — or an unset
 * WORDPRESS_API_URL — used to turn the front door into a 500 while every
 * other route carried on. The name and the tagline are decoration; the door's
 * job is to offer a way in, and it can do that with neither.
 *
 * The failure is not hidden: it is logged, and a monitor that watches for a
 * heading rather than a status code still sees the difference.
 */
async function getWordPressSite(): Promise<WordPressSite> {
  try {
    return (await wordpressFetch("/")) as WordPressSite;
  } catch (error) {
    console.error("The front door could not read the site from WordPress.", error);

    return {
      name: "Platform CLE",
      description: "",
    };
  }
}

/** One way in, with a word about where it leads. */
function PathCard({ path }: { path: Path }) {
  const card = (
    <Card className="h-full p-6 text-left hover:ring-foreground/20">
      <CardContent className="p-0">
        <h2 className="text-lg font-medium text-zinc-950">{path.label}</h2>
        <p className="mt-1 text-sm text-zinc-600">{path.description}</p>
      </CardContent>
    </Card>
  );

  const className = "block transition hover:shadow-sm";

  return path.external ? (
    <a href={path.href} className={className}>
      {card}
    </a>
  ) : (
    <Link href={path.href} className={className}>
      {card}
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
  const signedIn = await isAuthenticated();
  const paths = signedIn ? await pathsFor() : [];

  /*
   * A menu of one is not a menu.
   *
   * Signing in lands here, which is right for anyone with a choice to make
   * and a toll gate for everyone else: a participant has exactly one path,
   * and showing them a single card to click is asking them to confirm the
   * only thing they could have wanted. They go straight there instead.
   *
   * Only for a path inside the app. Bouncing someone out to wp-admin before
   * they have seen a single screen of this one would be a different and
   * much ruder decision.
   */
  if (paths.length === 1 && !paths[0].external) {
    redirect(paths[0].href);
  }

  const site = await getWordPressSite();

  return (
    <main className="flex flex-1 items-center justify-center bg-zinc-50 px-6 py-16">
      <div className="w-full max-w-3xl text-center">
        <h1 className="text-5xl font-semibold tracking-tight text-zinc-950">
          {decodeEntities(site.name)}
        </h1>

        {site.description !== "" && (
          <p className="mt-4 text-xl text-zinc-600">
            {decodeEntities(site.description)}
          </p>
        )}

        {signedIn ? (
          <>
            <div className="mt-10 grid gap-4 sm:grid-cols-2">
              {paths.map((path) => (
                <PathCard key={path.href} path={path} />
              ))}
            </div>

            {/*
              The way out. Every other screen carries the shared header; this
              one carries nothing, and it is where signing in lands.
            */}
            <form action={logoutAction} className="mt-10">
              <Button type="submit" variant="link" className="text-zinc-500">
                Log out
              </Button>
            </form>
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
