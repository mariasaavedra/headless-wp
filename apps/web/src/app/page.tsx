import { wordpressFetch } from "@/lib/wordpress";

type WordPressSite = {
  name: string;
  description: string;
};

async function getWordPressSite(): Promise<WordPressSite> {
  return wordpressFetch("/") as Promise<WordPressSite>;
}

export default async function Home() {
  const site = await getWordPressSite();

  return (
    <main className="flex min-h-screen items-center justify-center bg-zinc-50 px-6">
      <div className="max-w-3xl text-center">
        <h1 className="text-5xl font-semibold tracking-tight text-zinc-950">
          {site.name}
        </h1>

        <p className="mt-4 text-xl text-zinc-600">
          {site.description}
        </p>
      </div>
    </main>
  );
}