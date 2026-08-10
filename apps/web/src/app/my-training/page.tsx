import { redirect } from "next/navigation";

import { isAuthenticated } from "@/lib/auth";
import { getMyTraining, WordPressApiError } from "@/lib/wordpress";
import { logoutAction } from "@/app/actions/auth";

export default async function MyTrainingPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let training: unknown;
  let error: string | undefined;

  try {
    training = await getMyTraining();
  } catch (err) {
    error =
      err instanceof WordPressApiError
        ? "WordPress rejected the request. Please log in again."
        : "Unable to load training data right now.";
  }

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4 bg-zinc-50 px-6">
      <h1 className="text-2xl font-semibold text-zinc-950">My Training</h1>

      {error ? (
        <p className="text-sm text-red-600">{error}</p>
      ) : (
        <pre className="max-w-2xl overflow-auto rounded bg-white p-4 text-sm text-zinc-800 shadow">
          {JSON.stringify(training, null, 2)}
        </pre>
      )}

      <form action={logoutAction}>
        <button
          type="submit"
          className="rounded bg-zinc-950 px-3 py-2 text-white"
        >
          Log out
        </button>
      </form>
    </main>
  );
}
