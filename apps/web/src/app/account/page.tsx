import { redirect } from "next/navigation";

import { Card, CardContent } from "@pcle/ui/components/card";

import { PasswordForm, UsernameForm } from "@/components/account-forms";
import { renderAccessError } from "@/components/access-error";
import PageShell from "@/components/page-shell";
import { isAuthenticated } from "@/lib/auth";
import { getAccount, type Account } from "@/lib/wordpress";

export const dynamic = "force-dynamic";

/**
 * Your own account: the username you sign in with, and your password.
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

  return (
    <PageShell>
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
    </PageShell>
  );
}
