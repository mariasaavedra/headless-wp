"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";

import { setRoleAction } from "@/app/actions/people";
import type { GrantableRole, Person } from "@/lib/types";

/**
 * Changes one person's role.
 *
 * A select and a button rather than a select that submits on change: this
 * writes to somebody else's account, and a stray keystroke on a focused
 * dropdown should not be enough to do it. It is also a plain form, so it
 * works before JavaScript has loaded.
 */
export default function RoleChooser({
  person,
  roles,
}: {
  person: Person;
  roles: GrantableRole[];
}) {
  const [state, formAction, pending] = useActionState(setRoleAction, {});

  return (
    <form action={formAction} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="id" value={person.id} />
      <input type="hidden" name="name" value={person.name} />

      <label className="sr-only" htmlFor={`role-${person.id}`}>
        Role for {person.name}
      </label>

      <select
        id={`role-${person.id}`}
        name="role"
        defaultValue={person.role}
        className="h-8 rounded-lg border border-input bg-white px-2 text-sm text-zinc-900"
      >
        {roles.map((role) => (
          <option key={role.role} value={role.role}>
            {role.label}
          </option>
        ))}
      </select>

      <Button type="submit" variant="outline" disabled={pending}>
        {pending ? "Changing…" : "Change"}
      </Button>

      {state.error && (
        <p role="alert" className="w-full text-xs text-red-600">
          {state.error}
        </p>
      )}

      {state.changed && !state.error && (
        <p className="w-full text-xs text-emerald-700">
          {state.changed.name} is now {state.changed.role}.
        </p>
      )}
    </form>
  );
}
