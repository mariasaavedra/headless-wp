"use client";

import Link from "next/link";
import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";
import { Input } from "@pcle/ui/components/input";

import { loginAction } from "@/app/actions/auth";

export default function LoginPage() {
  const [state, formAction, pending] = useActionState(loginAction, {});

  return (
    <main className="flex min-h-screen items-center justify-center bg-zinc-50 px-6">
      <form action={formAction} className="w-full max-w-sm space-y-4">
        <h1 className="text-2xl font-semibold text-zinc-950">Log in</h1>

        <Input
          name="username"
          type="text"
          placeholder="Username"
          autoComplete="username"
          required
        />

        <Input
          name="password"
          type="password"
          placeholder="Password"
          autoComplete="current-password"
          required
        />

        {state.error && (
          <p className="text-sm text-red-600">{state.error}</p>
        )}

        <Button type="submit" disabled={pending} className="w-full">
          {pending ? "Logging in..." : "Log in"}
        </Button>

        {/* Somewhere to go for anyone who arrived here by accident. */}
        <p className="text-center text-sm">
          <Link href="/" className="text-zinc-500 hover:text-zinc-900 hover:underline">
            Back to the home page
          </Link>
        </p>
      </form>
    </main>
  );
}
