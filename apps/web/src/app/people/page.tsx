import { redirect } from "next/navigation";

import { Card, CardContent } from "@pcle/ui/components/card";

import { renderAccessError } from "@/components/access-error";
import PageShell from "@/components/page-shell";
import RoleChooser from "@/components/role-chooser";
import { isAuthenticated } from "@/lib/auth";
import type { People } from "@/lib/types";
import { getPeople } from "@/lib/wordpress";

export const dynamic = "force-dynamic";

/**
 * Who has an account, and what they may do.
 *
 * Staff can read this because the question it answers — does this person
 * already have an account? — comes up while enrolling a cohort, and the
 * answer used to require opening wp-admin. Changing a role is an
 * administrator's, which the rows say plainly rather than by offering a
 * control that would be refused.
 */
export default async function PeoplePage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let directory: People;

  try {
    directory = await getPeople();
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to this list",
      detail:
        "Accounts are visible to instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  return (
    <PageShell wide>
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        People
      </h1>

      <p className="mt-2 text-zinc-600">
        Everyone with an account on the platform. Creating accounts is done
        while enrolling a cohort, on a programme&rsquo;s report.
      </p>

      <Card className="mt-8 p-0">
        <CardContent className="overflow-x-auto p-6">
          <table className="w-full min-w-[40rem] text-left">
            <thead>
              <tr className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                <th className="pb-2 pr-4">Person</th>
                <th className="pb-2 pr-4">Role</th>
                <th className="pb-2">Change</th>
              </tr>
            </thead>

            <tbody>
              {directory.people.map((person) => (
                <tr
                  key={person.id}
                  className="border-t border-zinc-100 align-top"
                >
                  <td className="py-3 pr-4">
                    <div className="font-medium text-zinc-900">
                      {person.name}
                    </div>
                    <div className="text-xs text-zinc-500">{person.email}</div>
                  </td>

                  <td className="py-3 pr-4 text-sm text-zinc-600">
                    {person.role_label}
                  </td>

                  <td className="py-3">
                    {person.editable ? (
                      <RoleChooser person={person} roles={directory.roles} />
                    ) : (
                      /*
                       * Why, rather than an absence. An administrator looking
                       * at their own row and finding no control would wonder
                       * whether the screen was broken.
                       */
                      <span className="text-xs text-zinc-400">
                        {person.role === "administrator"
                          ? "Administrators are managed in WordPress"
                          : "Only an administrator may change a role"}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <p className="mt-6 text-sm text-zinc-500">
        Accounts are never deleted from here: progress, attendance and quiz
        attempts hang off them, and a credit claim may depend on those records.
      </p>
    </PageShell>
  );
}
