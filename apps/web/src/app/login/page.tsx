"use client";

import { useActionState } from "react";

import { loginAction } from "@/app/actions/auth";

export default function LoginPage() {
  const [state, formAction, pending] = useActionState(loginAction, {});

  return (
    <main className="flex min-h-screen items-center justify-center bg-zinc-50 px-6">
      <form action={formAction} className="w-full max-w-sm space-y-4">
        <h1 className="text-2xl font-semibold text-zinc-950">Log in</h1>

        <input
          name="username"
          type="text"
          placeholder="Username"
          autoComplete="username"
          required
          className="w-full rounded border border-zinc-300 px-3 py-2"
        />

        <input
          name="password"
          type="password"
          placeholder="Password"
          autoComplete="current-password"
          required
          className="w-full rounded border border-zinc-300 px-3 py-2"
        />

        {state.error && (
          <p className="text-sm text-red-600">{state.error}</p>
        )}

        <button
          type="submit"
          disabled={pending}
          className="w-full rounded bg-zinc-950 px-3 py-2 text-white disabled:opacity-50"
        >
          {pending ? "Logging in..." : "Log in"}
        </button>
      </form>
    </main>
  );
}
