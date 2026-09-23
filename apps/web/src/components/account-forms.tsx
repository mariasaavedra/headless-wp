"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";
import { Input } from "@pcle/ui/components/input";
import { Label } from "@pcle/ui/components/label";

import {
  changePasswordAction,
  changeUsernameAction,
  type AccountActionState,
} from "@/app/actions/account";

/** What an action said back: a refusal, or what changed. */
function Outcome({ state }: { state: AccountActionState }) {
  if (state.error) {
    return (
      <p role="alert" className="text-sm text-red-700">
        {state.error}
      </p>
    );
  }

  if (state.success) {
    return (
      <p role="status" className="text-sm text-emerald-700">
        {state.success}
      </p>
    );
  }

  return null;
}

function Field({
  id,
  label,
  hint,
  ...input
}: React.ComponentProps<typeof Input> & { id: string; label: string; hint?: string }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} aria-describedby={hint ? `${id}-hint` : undefined} {...input} />
      {hint && (
        <p id={`${id}-hint`} className="text-xs text-zinc-500">
          {hint}
        </p>
      )}
    </div>
  );
}

export function UsernameForm({ username }: { username: string }) {
  const [state, action, pending] = useActionState(changeUsernameAction, {});

  return (
    <form action={action} className="space-y-4">
      <Field
        id="account-username"
        name="username"
        label="New username"
        defaultValue={username}
        autoComplete="username"
        required
        minLength={3}
        maxLength={60}
        pattern="[A-Za-z0-9._\-]+"
        hint="3 to 60 characters: letters, numbers, dots, dashes and underscores."
      />
      <Field
        id="account-username-password"
        name="current_password"
        type="password"
        label="Current password"
        autoComplete="current-password"
        required
      />

      <Outcome state={state} />

      <Button type="submit" disabled={pending}>
        {pending ? "Changing…" : "Change username"}
      </Button>
    </form>
  );
}

export function PasswordForm() {
  const [state, action, pending] = useActionState(changePasswordAction, {});

  return (
    // Keyed on success so the fields empty once the change has gone through.
    <form key={state.success ?? "form"} action={action} className="space-y-4">
      <Field
        id="account-current-password"
        name="current_password"
        type="password"
        label="Current password"
        autoComplete="current-password"
        required
      />
      <Field
        id="account-new-password"
        name="new_password"
        type="password"
        label="New password"
        autoComplete="new-password"
        required
        minLength={12}
        hint="At least 12 characters. A few unrelated words is easier to remember than symbols."
      />
      <Field
        id="account-confirm-password"
        name="confirm_password"
        type="password"
        label="New password again"
        autoComplete="new-password"
        required
        minLength={12}
      />

      <Outcome state={state} />

      <Button type="submit" disabled={pending}>
        {pending ? "Changing…" : "Change password"}
      </Button>
    </form>
  );
}
