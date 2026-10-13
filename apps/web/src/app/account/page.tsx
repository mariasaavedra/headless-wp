import { redirect } from "next/navigation";

import { Card, CardContent } from "@pcle/ui/components/card";

import { setMyColourAction } from "@/app/actions/colours";
import { PasswordForm, UsernameForm } from "@/components/account-forms";
import { renderAccessError } from "@/components/access-error";
import SwatchMenu from "@/components/colours/swatch-menu";
import PageShell from "@/components/page-shell";
import { isAuthenticated } from "@/lib/auth";
import { swatchLabel } from "@/lib/colours";
import { decodeEntities } from "@/lib/html";
import {
  getAccount,
  getMyColours,
  type Account,
  type MyColours,
} from "@/lib/wordpress";

export const dynamic = "force-dynamic";

/**
 * Your own account: the username you sign in with, your password, and the
 * colours you see your programmes in.
 *
 * Open to everyone signed in, whatever their role. Both changes ask for the
 * current password, and a new password signs out every other device — the
 * page says so before it happens, not only after.
 */
export default async function AccountPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let account: Account;

  try {
    account = await getAccount();
  } catch (error) {
    return renderAccessError(error, {
      title: "Your account could not be loaded",
      detail: "Sign out and in again. If it keeps happening, contact your programme administrator.",
    });
  }

  /*
   * Optional: the account is what this page is for, and a failure here
   * should cost the colours section, not the password form.
   */
  let colours: MyColours | null = null;

  try {
    colours = await getMyColours();
  } catch {
    colours = null;
  }

  return (
    <PageShell code="P7">
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        Your account
      </h1>
      <p className="mt-2 text-zinc-600">
        Signed in as <span className="font-medium text-zinc-900">{account.username}</span>
        {account.email && <> · {account.email}</>}
      </p>

      <Card className="mt-8">
        <CardContent className="space-y-4">
          <div>
            <h2 className="text-lg font-semibold text-zinc-950">Username</h2>
            <p className="mt-1 text-sm text-zinc-600">
              What you type to sign in. You stay signed in after changing it.
            </p>
          </div>
          <UsernameForm username={account.username} />
        </CardContent>
      </Card>

      <Card className="mt-6">
        <CardContent className="space-y-4">
          <div>
            <h2 className="text-lg font-semibold text-zinc-950">Password</h2>
            <p className="mt-1 text-sm text-zinc-600">
              Changing it signs you out on every other device. You stay signed
              in here, and WordPress emails you to say it changed.
            </p>
          </div>
          <PasswordForm />
        </CardContent>
      </Card>

      {colours && colours.programs.length > 0 && (
        <Card className="mt-6">
          <CardContent className="space-y-4">
            <div>
              <h2 className="text-lg font-semibold text-zinc-950">My colours</h2>
              <p className="mt-1 text-sm text-zinc-600">
                Recolour the programmes on your My Training to tell them apart
                at a glance. Only you see these colours.
              </p>
              {!colours.shown && (
                <p className="mt-3 rounded-md bg-zinc-100 px-3 py-2 text-sm text-zinc-600">
                  Colours are switched off for participants at the moment, so
                  these will not show until an administrator turns them back on.
                </p>
              )}
            </div>

            <ul className="divide-y divide-zinc-100 border-t border-zinc-100">
              {colours.programs.map((program) => {
                const title = decodeEntities(program.title);
                const now = program.color
                  ? `${swatchLabel(program.color)}, your colour`
                  : program.program_color
                    ? `${swatchLabel(program.program_color)}, the programme's colour`
                    : "No colour";

                return (
                  <li key={program.id} className="flex items-center gap-3 py-3">
                    <SwatchMenu
                      action={setMyColourAction}
                      fields={{ program_id: program.id }}
                      current={program.color}
                      shown={program.color ?? program.program_color}
                      label={title}
                      now={now}
                      clear={{ label: "Use the programme's colour", swatch: program.program_color }}
                    />
                    <span className="min-w-0 flex-1 font-medium text-zinc-900">{title}</span>
                    <span className="text-sm text-zinc-500">{now}</span>
                  </li>
                );
              })}
            </ul>
          </CardContent>
        </Card>
      )}
    </PageShell>
  );
}
