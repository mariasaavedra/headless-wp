"use client";

import { useActionState } from "react";
import type { ReactNode } from "react";

import type { BuilderActionState } from "@/app/actions/authoring";

type ActionFormProps = {
  action: (
    state: BuilderActionState,
    formData: FormData
  ) => Promise<BuilderActionState>;
  children: ReactNode;
  className?: string;
};

/**
 * A real form wired to a server action.
 *
 * The form works before any JavaScript loads — submitting it posts and the
 * page re-renders. useActionState only adds two things on top: the fields go
 * inert while the request is in flight, and a refused save says so instead of
 * looking like it worked.
 *
 * That last part matters more here than on the participant screens: an author
 * who thinks a change saved and closes the tab has lost it.
 */
export default function ActionForm({
  action,
  children,
  className,
}: ActionFormProps) {
  const [state, formAction, pending] = useActionState(action, {});

  return (
    <form action={formAction} className={className}>
      <fieldset disabled={pending} className="contents">
        {children}
      </fieldset>

      {state.error && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {state.error}
        </p>
      )}
    </form>
  );
}
