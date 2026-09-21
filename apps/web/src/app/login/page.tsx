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
export default async function LoginPage() {
  if (await isAuthenticated()) {
    redirect("/");
  }

  return (
    <main className="flex flex-1 items-center justify-center bg-zinc-50 px-6">
      <LoginForm />
    </main>
  );
}
