import { redirect } from "next/navigation";

import LoginForm from "@/components/login-form";
import { isAuthenticated } from "@/lib/auth";

/*
 * Depends on the cookie, so it can never be prerendered: a cached signed-out
 * login screen is exactly what this route must not serve.
 */
export const dynamic = "force-dynamic";

/**
 * Sign in — or, for someone who is already signed in, don't.
 *
 * Asking a reader who holds a valid session to type their password again is
 * the app failing to know something it does know. They are sent to the menu,
 * which is where signing in would have taken them anyway.
 */
export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;

  /*
   * A session WordPress refused still leaves its cookie behind, so "has a
   * cookie" is not "signed in" when the refusal is what sent them here.
   * Signing in again overwrites it.
   */
  const sessionEnded = params.session === "ended";

  if (!sessionEnded && (await isAuthenticated())) {
    redirect("/");
  }

  /*
   * A password change ends every session. If signing straight back in with
   * the new one failed, the person lands here and should know why.
   */
  const passwordChanged = params.changed === "password";

  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4 bg-zinc-50 px-6">
      {sessionEnded && !passwordChanged && (
        <p role="status" className="max-w-sm text-center text-sm text-zinc-600">
          You were signed out — perhaps your password was changed on another
          device. Sign in again.
        </p>
      )}
      {passwordChanged && (
        <p role="status" className="max-w-sm text-center text-sm text-emerald-700">
          Your password is changed. Sign in with the new one.
        </p>
      )}
      <LoginForm />
    </main>
  );
}
