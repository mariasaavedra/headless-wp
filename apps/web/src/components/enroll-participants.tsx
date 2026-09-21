"use client";

import { useActionState } from "react";

import { Button } from "@pcle/ui/components/button";
import { Card, CardContent } from "@pcle/ui/components/card";
import { Label } from "@pcle/ui/components/label";
import { Textarea } from "@pcle/ui/components/textarea";

import { enrollAction } from "@/app/actions/enrollment";
import type { EnrollmentOutcome, EnrollmentPerson } from "@/lib/types";

/** What each outcome means to whoever pasted the list. */
const OUTCOMES: Record<EnrollmentOutcome, { label: string; tone: string }> = {
  enrolled: { label: "enrolled", tone: "text-emerald-700" },
  already: { label: "already enrolled", tone: "text-zinc-500" },
  created: { label: "account created", tone: "text-emerald-700" },
  unknown: { label: "no account yet", tone: "text-amber-700" },
  invalid: { label: "not an email address", tone: "text-red-600" },
  failed: { label: "could not be enrolled", tone: "text-red-600" },
};

function Outcome({ person }: { person: EnrollmentPerson }) {
  const outcome = OUTCOMES[person.outcome] ?? {
    label: person.outcome,
    tone: "text-zinc-500",
  };

  return (
    <li className="flex flex-wrap items-baseline gap-x-2 py-1">
      <span className="text-sm text-zinc-900">{person.name || person.email}</span>
      {person.name && (
        <span className="text-xs text-zinc-500">{person.email}</span>
      )}
      <span className={`text-xs ${outcome.tone}`}>{outcome.label}</span>
    </li>
  );
}

/**
 * Adds participants to a programme by email address.
 *
 * A plain form, so it works before any JavaScript has loaded; useActionState
 * only adds the pending state and the report of what happened.
 *
 * Every address is accounted for by name in the result rather than summarised
 * as a count: the interesting case is the one that did not work, and a reader
 * who pasted twelve addresses needs to know which of them to chase.
 */
const CHECKBOX_CLASS = "size-4 rounded border-input accent-primary";

export default function EnrollParticipants({
  programId,
  canInvite,
}: {
  programId: number;
  /**
   * Whether this reader may create accounts. The endpoint decides regardless
   * — this only stops offering a control whose request would come back with
   * nothing done.
   */
  canInvite: boolean;
}) {
  const [state, formAction, pending] = useActionState(enrollAction, {});

  return (
    <Card className="mt-8">
      <CardContent className="p-6">
        <form action={formAction}>
          <input type="hidden" name="program_id" value={programId} />

          <Label htmlFor="emails" className="text-sm font-medium text-zinc-900">
            Add participants
          </Label>

          <p className="mt-1 text-sm text-zinc-500">
            Email addresses separated by commas, semicolons or new lines.
            {canInvite
              ? " Addresses with no account are reported back, unless you ask for accounts to be created below."
              : " Addresses with no account are reported back; creating accounts is an administrator's to do."}
          </p>

          <Textarea
            id="emails"
            name="emails"
            rows={3}
            className="mt-3"
            placeholder={"someone@example.org\nsomeone.else@example.org"}
          />

          {/*
            Offered only to a reader who may create accounts, and unchecked
            every time. Inviting strangers is not a setting someone should
            inherit from the last time they used this box.
          */}
          {canInvite && (
            <label className="mt-3 flex items-start gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                name="create"
                className={`${CHECKBOX_CLASS} mt-0.5`}
              />
              <span>
                Create accounts for addresses that have none, and email them a
                link to set their own password.
                <span className="block text-xs text-zinc-500">
                  WordPress sends the link. No password is chosen here, and the
                  account cannot be deleted from this screen.
                </span>
              </span>
            </label>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={pending}>
              {pending ? "Enrolling…" : "Enrol"}
            </Button>

            {state.error && (
              <p role="alert" className="text-sm text-red-600">
                {state.error}
              </p>
            )}
          </div>
        </form>

        {state.result && (
          <div className="mt-4 border-t border-zinc-100 pt-4">
            {/*
              Labelled as history, not as state. This panel survives a
              removal further down the page — the table re-reads the roster,
              this component keeps its own last answer — and "1 person
              enrolled" sitting above "nobody is enrolled" would otherwise
              read as a contradiction rather than as two true things about
              different moments.
            */}
            <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
              The list you last pasted
            </p>

            <p className="mt-1 text-sm text-zinc-600">
              {state.result.enrolled}{" "}
              {state.result.enrolled === 1 ? "person" : "people"} enrolled
              {state.result.created > 0 &&
                `, ${state.result.created} ${
                  state.result.created === 1 ? "account" : "accounts"
                } created`}
              {state.result.skipped > 0 && `, ${state.result.skipped} skipped`}.
            </p>

            {/*
              The one case where the result differs from what was asked for.
              Silence here would look like the addresses were simply unknown.
            */}
            {state.result.create_requested &&
              !state.result.create_permitted && (
                <p className="mt-1 text-sm text-amber-700">
                  Accounts were not created: that needs an administrator. The
                  addresses that already had one were still enrolled.
                </p>
              )}

            <ul className="mt-2">
              {state.result.people.map((person) => (
                <Outcome key={person.email} person={person} />
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
